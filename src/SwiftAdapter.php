<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nimbusoft\Flysystem\OpenStack;

use DateTimeInterface;
use GuzzleHttp\Psr7\StreamWrapper;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

use function is_resource;

final class SwiftAdapter implements FilesystemAdapter
{
    private Container $container;

    private PathPrefixer $prefixer;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        Container $container,
        StreamFactoryInterface $streamFactory,
        string $prefix = '',
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->container = $container;
        $this->streamFactory = $streamFactory;
        $this->prefixer = new PathPrefixer($prefix);
        $this->visibility = $visibility ?: new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $stream = $this->readStream($source);

        if (false === is_resource($stream)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $this->writeStream($destination, $stream, $config);

        fclose($stream);
    }

    public function createDirectory(string $path, Config $config): void
    {
        // TODO
    }

    public function delete(string $path): void
    {
        $object = $this->getObjectInstance($path);

        try {
            $object->delete();
        } catch (BadResponseError $e) {
            throw UnableToDeleteFile::atLocation($path, '', $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        // Make sure a slash is added to the end.
        $path = rtrim(trim($path), '/') . '/';

        // To be safe, don't delete everything.
        if ('/' === $path) {
            return;
        }

        $objects = $this->container->listObjects([
            'prefix' => $this->prefixer->prefixPath($path),
        ]);

        try {
            foreach ($objects as $object) {
                $object->containerName = $this->container->name;
                $object->delete();
            }
        } catch (BadResponseError $e) {
            throw UnableToDeleteDirectory::atLocation($path, '', $e);
        }
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->container->objectExists($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->prefixer->prefixPath($path);

        $objectList = $this->container->listObjects([
            'prefix' => $location,
        ]);

        foreach ($objectList as $object) {
            yield $this->normalizeObject($object);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $object = $this->getObject($source);
        $newLocation = $this->prefixer->prefixPath($destination);
        $destination = '/' . $this->container->name . '/' . ltrim($newLocation, '/');

        try {
            $object->copy(compact('destination'));
        } catch (BadResponseError $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }

        $object->delete();
    }

    public function read(string $path): string
    {
        $object = $this->getObject($path);

        $stream = $object->download();
        $stream->rewind();

        return $stream->getContents();
    }

    public function readStream(string $path)
    {
        $object = $this->getObject($path);

        $stream = $object->download();
        $stream->rewind();

        return StreamWrapper::getResource($stream);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path);
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this
            ->container
            ->createObject(
                $this->getWriteData($this->prefixer->prefixPath($path), $config) + ['content' => $contents]
            );
    }

    public function writeStream($path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw new InvalidArgumentException('The $contents parameter must be a resource.');
        }

        $data = $this->getWriteData($this->prefixer->prefixPath($path), $config) +
            ['stream' => $this->streamFactory->createStreamFromResource($contents)];
        $data['segmentSize'] = $config->get('swiftSegmentSize', 104857600);
        $data['segmentContainer'] = $config->get('swiftSegmentContainer', $this->container->name);

        $this
            ->container
            ->createLargeObject(
                $data
            );
    }

    private function getMetadata(string $path): FileAttributes
    {
        return $this->normalizeObject($this->getObject($path));
    }

    private function getObject(string $path): StorageObject
    {
        $object = $this->getObjectInstance($path);
        $object->retrieve();

        return $object;
    }

    private function getObjectInstance(string $path): StorageObject
    {
        $location = $this->prefixer->prefixPath($path);

        return $this->container->getObject($location);
    }

    private function getWriteData(string $path, Config $config): array
    {
        return ['name' => $path];
    }

    private function normalizeObject(StorageObject $object): FileAttributes
    {
        $name = $this->prefixer->stripPrefix($object->name);

        if ($object->lastModified instanceof DateTimeInterface) {
            $timestamp = $object->lastModified->getTimestamp();
        } else {
            $timestamp = $object->lastModified;
        }

        return new FileAttributes(
            $name,
            (int) $object->contentLength,
            null,
            (int) $timestamp,
            $object->contentType,
            [
                'type' => 'file',
                'dirname' => $this->prefixer->prefixPath($object->name),
            ]
        );
    }
}

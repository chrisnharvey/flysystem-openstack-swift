<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nimbusoft\Flysystem\OpenStack;

use DateTimeInterface;
use GuzzleHttp\Psr7\Stream;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use Throwable;

class SwiftAdapter implements FilesystemAdapter
{
    protected Container $container;

    protected PathPrefixer $prefixer;

    /**
     * Create a new instance.
     *
     * @param Container $container The OpenStack container.
     * @param string $prefix Optional path prefix to apply to all operations.
     */
    public function __construct(
        Container $container,
        string $prefix = ''
    ) {
        $this->container = $container;
        $this->prefixer = new PathPrefixer($prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->prefixer->prefixPath($path);
        $data = $this->getWriteData($path, $config);
        $data['content'] = $contents;

        try {
            $this->container->createObject($data);
        } catch (BadResponseError $e) {
            throw UnableToWriteFile::atLocation($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw new InvalidArgumentException('The $contents parameter must be a resource.');
        }

        $stream = $this->getStreamFromResource($contents);
        $path = $this->prefixer->prefixPath($path);
        $data = $this->getWriteData($path, $config);
        $data['stream'] = $stream;

        try {
            // Create large object if the stream is larger than 300 MiB (default).
            if ($stream->getSize() > $config->get('swiftLargeObjectThreshold', 314572800)) {
                // Set the segment size to 100 MiB by default as suggested in OVH docs.
                $data['segmentSize'] = $config->get('swiftSegmentSize', 104857600);
                $data['segmentContainer'] = $config->get('swiftSegmentContainer', $this->container->name);

                $this->container->createLargeObject($data);
            } else {
                $this->container->createObject($data);
            }
        } catch (BadResponseError $e) {
            throw UnableToWriteFile::atLocation($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (BadResponseError $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        try {
            $object = $this->getObjectInstance($path);
            $object->delete();
        } catch (BadResponseError $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $path): void
    {
        // Make sure a slash is added to the end.
        $path = rtrim(trim($path), '/') . '/';

        // To be safe, don't delete everything.
        if ($path === '/') {
            throw UnableToDeleteDirectory::atLocation($path, 'Will not delete root.');
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
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function fileExists(string $path): bool
    {
        try {
            return $this->container->objectExists($this->prefixer->prefixPath($path));
        } catch (BadResponseError $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function directoryExists(string $path): bool
    {
        try {
            return $this->listContents($path, false)->valid();
        } catch (Throwable $e) {
            if (class_exists(UnableToCheckDirectoryExistence::class)) {
                throw UnableToCheckDirectoryExistence::forLocation($path, $e);
            }

            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        try {
            $object = $this->getObject($path);
            $stream = $object->download();
            $stream->rewind();
            $contents = $stream->getContents();
        } catch (BadResponseError $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        try {
            $resource = $this->getObject($path)->download()->detach();
        } catch (BadResponseError $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }

        if (is_null($resource)) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $resource;
    }

    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'fileSize');
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'mimeType');
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'lastModified');
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $newLocation = $this->prefixer->prefixPath($destination);
        $destination = '/' . $this->container->name . '/' . ltrim($newLocation, '/');

        try {
            $this->getObjectInstance($source)->copy(compact('destination'));
        } catch (BadResponseError $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Not supported.');
    }

    protected function getWriteData(string $path, Config $config): array
    {
        return ['name' => $path];
    }

    protected function getObjectInstance(string $path): StorageObject
    {
        $location = $this->prefixer->prefixPath($path);

        return $this->container->getObject($location);
    }

    protected function getObject(string $path): StorageObject
    {
        $object = $this->getObjectInstance($path);
        $object->retrieve();

        return $object;
    }

    protected function normalizeObject(StorageObject $object): FileAttributes
    {
        $name = $this->prefixer->stripPrefix($object->name);

        if ($object->lastModified instanceof DateTimeInterface) {
            $timestamp = $object->lastModified->getTimestamp();
        } else {
            $timestamp = strtotime($object->lastModified);
        }

        return new FileAttributes(
            $name,
            (int) $object->contentLength,
            null,
            $timestamp,
            $object->contentType,
            [
                'type' => 'file',
            ]
        );
    }

    protected function getMetadata(string $path, string $type): FileAttributes
    {
        try {
            return $this->normalizeObject($this->getObject($path));
        } catch (BadResponseError $e) {
            throw UnableToRetrieveMetadata::$type($path, $e->getMessage(), $e);
        }
    }

    /**
     * @param resource $resource
     */
    protected function getStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }
}

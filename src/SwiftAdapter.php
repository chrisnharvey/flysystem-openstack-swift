<?php

namespace Nimbusoft\Flysystem\OpenStack;

use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\Object;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;

class SwiftAdapter extends AbstractAdapter
{
    use StreamedCopyTrait;
    use NotSupportingVisibilityTrait;

    /**
     * @var Container
     */
    protected $container;

    /**
     * Constructor
     *
     * @param Container $container
     * @param string    $prefix
     */
    public function __construct(Container $container, $prefix = null)
    {
        $this->setPathPrefix($prefix);
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $data = [
            'name' => $path
        ];

        $type = 'content';

        if (is_a($contents, 'GuzzleHttp\Psr7\Stream')) {
            $type = 'stream';
        }

        $data[$type] = $contents;

        // Check large object >3G
        $metadata = $this->getSize($path);
        if ($metadata && isset($metadata['size']) && $metadata['size'] > 3221225472) {
            // optional: specify the size of each segment in bytes - 1GB
            $options['segmentSize'] = 1073741824;
            // optional: specify the container where the segments live. This does not necessarily have to be the
            // same as the container which holds the manifest file
            $options['segmentContainer'] = $this->container->name;

            $response = $this->container->createLargeObject($data);
        } else {
            $response = $this->container->createObject($data);
        }

        return $this->normalizeObject($response);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, new Stream($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->write($path, new Stream($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $object = $this->getObject($path);
        $newLocation = $this->applyPathPrefix($newpath);
        $destination = '/'.$this->container->name.'/'.ltrim($newLocation, '/');

        try {
            $response = $object->copy(compact('destination'));
        } catch (BadResponseError $e) {
            return false;
        }

        $object->delete();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $object = $this->getObject($path);

        try {
            $object->delete();
        } catch (BadResponseError $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $objects = $this->container->listObjects([
            'prefix' => $this->applyPathPrefix($dirname)
        ]);

        try {
            foreach ($objects as $object) {
                $object->delete();
            }
        } catch (BadResponseError $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        try {
            $object = $this->getObject($path);
        } catch (BadResponseError $e) {
            $code = $e->getResponse()->getStatusCode();

            if ($code == 404) return false;

            throw $e;
        }

        return $this->normalizeObject($object);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $object = $this->getObject($path);
        $data = $this->normalizeObject($object);
        $data['contents'] = $object->download()->getContents();

        return $data;
    }

    /**
    * {@inheritdoc}
    */
    public function readStream($path)
    {
       $object = $this->getObject($path);
       $data = $this->normalizeObject($object);
       $data['stream'] = StreamWrapper::getResource($object->download());

       return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $location = $this->applyPathPrefix($directory);

        $objectList = $this->container->listObjects([
            'prefix' => $directory
        ]);

        $response = iterator_to_array($objectList);

        return Util::emulateDirectories(array_map([$this, 'normalizeObject'], $response));
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $object = $this->getObject($path);

        return $this->normalizeObject($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get an object.
     *
     * @param string $path
     *
     * @return Object
     */
    protected function getObject($path)
    {
        $location = $this->applyPathPrefix($path);

        $object = $this->container->getObject($location);
        $object->retrieve();

        return $object;
    }

    /**
     * Normalize Openstack "Object" object into an array
     *
     * @param Object $object
     * @return array
     */
    protected function normalizeObject(Object $object)
    {
        $name = $this->removePathPrefix($object->name);
        $mimetype = explode('; ', $object->contentType);

        return [
            'type'      => 'file',
            'dirname'   => Util::dirname($name),
            'path'      => $name,
            'timestamp' => strtotime($object->lastModified),
            'mimetype'  => reset($mimetype),
            'size'      => $object->contentLength,
        ];
    }
}

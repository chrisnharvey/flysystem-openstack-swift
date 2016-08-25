<?php

namespace Harvey\Flysystem\OpenStack;

use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use OpenCloud\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\Object;

class SwiftAdapter extends AbstractAdapter
{
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
            'name'    => $path
        ];

        $type = 'contents';

        if (is_a($contents, 'GuzzleHttp\Psr7\Stream')) {
            $type = 'stream';
        }

        $data[$type] = $contents;

        $response = $this->container->createObject($data);

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

        $response = $object->copy(compact('destination'));

        if ($response->getStatusCode() !== 201) {
            return false;
        }
        $object->delete();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        $object = $this->getObject($path);
        $newLocation = $this->applyPathPrefix($newpath);
        $destination = '/'.$this->container->name.'/'.ltrim($newLocation, '/');

        $response = $object->copy(compact('destination'));

        if ($response->getStatusCode() !== 201) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $object = $this->getObject($path);

        $response = $object->delete();

        if ($response->getStatusCode() !== 204) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $paths = [];
        $prefix = '/'.$this->container->getName().'/';
        $location = $this->applyPathPrefix($dirname);
        $objects = $this->container->listObjects([
            'prefix' => $location
        ]);

        foreach ($objects as $object) {
            $object->delete();
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
        $data['contents'] = (string) $object->content;

        return $data;
    }

    /**
    * {@inheritdoc}
    */
    public function readStream($path)
    {
       $object = $this->getObject($path);
       $data = $this->normalizeObject($object);
       $responseBody = $object->content;
       $data['stream'] = $responseBody->getStream();
       $responseBody->detachStream();

       return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $response = [];
        $marker = null;
        $location = $this->applyPathPrefix($directory);

        while (true) {
            $objectList = $this->container->listObjects([
                'prefix' => $location,
                'marker' => $marker
            ]);

            if (count($objectList) === 0) break;

            $response = array_merge($response, iterator_to_array($objectList));
            $marker = end($response)->getName();
        }

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
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility($path, $visibility)
    {
        return true;
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

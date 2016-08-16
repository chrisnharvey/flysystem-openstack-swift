<?php

namespace Harvey\Flysystem\OpenStack;

use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
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

        $response = $this->container->createObject([
            'name'    => $path,
            'content' => $contents
        ]);

        return $this->normalizeObject($response);
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

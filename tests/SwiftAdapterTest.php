<?php

use Mockery;
use League\Flysystem\Config;
use Harvey\Flysystem\OpenStack\SwiftAdapter;

class SwiftAdapterTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->config = new Config([]);
        $this->container = Mockery::mock('OpenStack\ObjectStore\v1\Models\Container');
        $this->object = Mockery::mock('OpenStack\ObjectStore\v1\Models\Object');
        $this->adapter = new SwiftAdapter($this->container);
    }

    public function testWrite()
    {
        $this->container->shouldReceive('createObject')->with([
            'name' => 'hello',
            'content' => 'world'
        ])->andReturn($this->object);

        $this->adapter->write('hello', 'world', $this->config);
    }

    public function tearDown()
    {
        Mockery::close();
    }
}

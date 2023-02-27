<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Mockery\LegacyMockInterface;
use Nimbusoft\Flysystem\OpenStack\SwiftAdapter;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use PHPUnit\Framework\TestCase;

class SwiftAdapterTest extends TestCase
{
    private SwiftAdapter $adapter;

    private Config $config;

    private LegacyMockInterface $container;

    private LegacyMockInterface $object;

    protected function setUp(): void
    {
        $this->config = new Config([]);
        $this->container = Mockery::mock(Container::class);
        $this->container->name = 'container-name';
        $this->object = Mockery::mock(StorageObject::class);
        // Object properties.
        $this->object->name = 'name';
        $this->object->contentType = 'text/html; charset=UTF-8';
        $this->object->lastModified = new DateTimeImmutable('@1628624822');

        $this->adapter = new SwiftAdapter($this->container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testDelete()
    {
        $this->object->shouldNotReceive('retrieve');
        $this->object->shouldReceive('delete')->once();

        $this->container->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $response = $this->adapter->delete('hello');

        $this->assertNull($response);
    }

    public function testCreateDirectory()
    {
        $this->expectException(UnableToCreateDirectory::class);
        $this->adapter->createDirectory('hello', $this->config);
    }

    public function testDeleteDirectory()
    {
        $times = mt_rand(1, 10);

        $generator = function () use ($times) {
            for ($i = 1; $i <= $times; ++$i) {
                yield $this->object;
            }
        };

        $objects = $generator();

        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'hello/',
            ])
            ->andReturn($objects);

        $this->object->shouldReceive('delete')->times($times);

        $response = $this->adapter->deleteDirectory('hello');

        $this->assertNull($response);
    }

    public function testDeleteDirectoryRoot()
    {
        $this->expectException(UnableToDeleteDirectory::class);
        $this->adapter->deleteDirectory('');
    }

    public function testFileExists()
    {
        $this->container->shouldReceive('objectExists')
            ->once()
            ->with('hello')
            ->andReturn(true);

        $fileExists = $this->adapter->fileExists('hello');

        $this->assertTrue($fileExists);
    }

    public function testDirectoryExists()
    {
        $generator = function () {
            yield $this->object;
        };

        $objects = $generator();

        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'hello/',
                'delimiter' => '/',
            ])
            ->andReturn($objects);

        $generator = function () {
            yield from [];
        };

        $objects = $generator();

        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'world/',
                'delimiter' => '/',
            ])
            ->andReturn($objects);

        $this->assertTrue($this->adapter->directoryExists('hello'));
        $this->assertFalse($this->adapter->directoryExists('world'));
    }

    public function testListContents()
    {
        $times = mt_rand(1, 10);

        $generator = function () use ($times) {
            for ($i = 1; $i <= $times; ++$i) {
                yield $this->object;
            }
        };

        $objects = $generator();

        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'hello/',
                'delimiter' => '/',
            ])
            ->andReturn($objects);

        $expect = [
            'path' => 'name',
            'type' => 'file',
            'last_modified' => 1628624822,
            'mime_type' => 'text/html; charset=UTF-8',
            'visibility' => null,
            'file_size' => 0,
            'extra_metadata' => [],
        ];

        $contents = $this->adapter->listContents('hello', false);
        $count = 0;

        foreach ($contents as $file) {
            $this->assertEquals($expect, $file->jsonSerialize());
            $count += 1;
        }

        $this->assertEquals($times, $count);
    }

    public function testListContentsPseudoDirectory()
    {
        $this->object->name = 'name/';
        $generator = function () {
            yield $this->object;
        };
        $objects = $generator();
        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'hello/',
                'delimiter' => '/',
            ])
            ->andReturn($objects);

        $expect = [
            'path' => 'name',
            'type' => 'dir',
            'visibility' => null,
            'extra_metadata' => [],
            'last_modified' => null,
        ];

        $contents = iterator_to_array($this->adapter->listContents('hello', false));
        $this->assertEquals($expect, $contents[0]->jsonSerialize());
    }

    public function testListContentsDeep()
    {
        $times = mt_rand(1, 10);

        $generator = function () {
            yield $this->object;
        };

        $objects = $generator();

        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'hello/',
            ])
            ->andReturn($objects);

        $expect = [
            'path' => 'name',
            'type' => 'file',
            'last_modified' => 1628624822,
            'mime_type' => 'text/html; charset=UTF-8',
            'visibility' => null,
            'file_size' => 0,
            'extra_metadata' => [],
        ];

        $contents = $this->adapter->listContents('hello', true);

        foreach ($contents as $file) {
            $this->assertEquals($expect, $file->jsonSerialize());
        }
    }

    public function testListContentsPseudoDirectoryDeep()
    {
        $this->object->name = 'pseudo/directory/name';
        $generator = function () {
            yield $this->object;
        };
        $objects = $generator();
        $this->container->shouldReceive('listObjects')
            ->once()
            ->with([
                'prefix' => 'pseudo/',
            ])
            ->andReturn($objects);

        $expect = [
            [
                'path' => 'pseudo/directory',
                'type' => 'dir',
                'visibility' => null,
                'extra_metadata' => [],
                'last_modified' => null,
            ],
            [
                'path' => 'pseudo/directory/name',
                'type' => 'file',
                'last_modified' => 1628624822,
                'mime_type' => 'text/html; charset=UTF-8',
                'visibility' => null,
                'file_size' => 0,
                'extra_metadata' => [],
            ],
        ];

        $contents = $this->adapter->listContents('pseudo', true);
        foreach ($contents as $index => $value) {
            $this->assertEquals($expect[$index], $value->jsonSerialize());
        }
    }

    public function testMove()
    {
        $this->object->shouldReceive('copy')->once()->with([
            'destination' => '/container-name/world',
        ]);
        $this->object->shouldReceive('delete')->once();

        $this->container->shouldReceive('getObject')
            ->twice()
            ->with('hello')
            ->andReturn($this->object);

        $response = $this->adapter->move('hello', 'world', $this->config);

        $this->assertNull($response);
    }

    public function testRead()
    {
        $stream = Mockery::mock(Stream::class);
        $stream->shouldReceive('close');
        $stream->shouldReceive('rewind');
        $stream->shouldReceive('getContents')->once()->andReturn('hello world');

        $this->object->shouldReceive('retrieve')->once();
        $this->object->shouldReceive('download')
            ->once()
            ->andReturn($stream);

        $this->container->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $data = $this->adapter->read('hello');

        $this->assertEquals($data, 'hello world');
    }

    public function testReadStream()
    {
        $resource = fopen('data://text/plain;base64,' . base64_encode('world'), 'rb');
        $stream = new Stream($resource);

        $this->object->shouldReceive('retrieve')->once();
        $this->object->shouldReceive('download')
            ->once()
            ->andReturn($stream);

        $this->container->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $data = $this->adapter->readStream('hello');

        $this->assertEquals('world', stream_get_contents($data));
    }

    public function testWrite()
    {
        $this->container->shouldReceive('createObject')
            ->once()
            ->with([
                'name' => 'hello',
                'content' => 'world',
                'contentType' => 'text/plain',
                'detectContentType' => true,
            ])
            ->andReturn($this->object);

        $config = $this->config->extend([
            'contentType' => 'text/plain',
            'detectContentType' => true,
        ]);

        $response = $this->adapter->write('hello', 'world', $config);

        $this->assertNull($response);
    }

    public function testWriteDeleteAt()
    {
        $time = time() + 3600;
        $this->container->shouldReceive('createObject')
            ->once()
            ->with([
                'name' => 'hello',
                'content' => 'world',
                'deleteAt' => $time,
            ])
            ->andReturn($this->object);

        $config = $this->config->extend([
            'deleteAt' => $time
        ]);

        $response = $this->adapter->write('hello', 'world', $config);

        $this->assertNull($response);
    }

    public function testWriteDeleteAfter()
    {
        $this->container->shouldReceive('createObject')
            ->once()
            ->with([
                'name' => 'hello',
                'content' => 'world',
                'deleteAfter' => 3600,
            ])
            ->andReturn($this->object);

        $config = $this->config->extend([
            'deleteAfter' => 3600
        ]);

        $response = $this->adapter->write('hello', 'world', $config);

        $this->assertNull($response);
    }

    public function testWriteStream()
    {
        $stream = fopen('data://text/plain;base64,'.base64_encode('world'), 'r');

        $this->adapter = new SwiftAdapterStub($this->container);
        $this->adapter->streamMock = Mockery::mock(Stream::class);

        $this->adapter->streamMock
            ->shouldReceive('getSize')
            ->once()
            ->andReturn(104857600); // 100 MB

        $this->container->shouldReceive('createObject')->once()->with([
            'name' => 'hello',
            'stream' => $this->adapter->streamMock,
            'contentType' => 'text/plain',
            'detectContentType' => true,
        ])->andReturn($this->object);

        $config = $this->config->extend([
            'contentType' => 'text/plain',
            'detectContentType' => true,
        ]);

        $response = $this->adapter->writeStream('hello', $stream, $config);

        $this->assertNull($response);
    }

    public function testWriteLargeStream()
    {
        $stream = fopen('data://text/plain;base64,'.base64_encode('world'), 'r');

        $this->adapter = new SwiftAdapterStub($this->container);
        $this->adapter->streamMock = Mockery::mock(Stream::class);

        $this->adapter->streamMock
            ->shouldReceive('getSize')
            ->once()
            ->andReturn(419430400); // 400 MB

        $this->container->shouldReceive('createLargeObject')
            ->once()
            ->with([
                'name' => 'hello',
                'stream' => $this->adapter->streamMock,
                'segmentSize' => 104857600, // 100 MiB
                'segmentContainer' => 'container-name',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->writeStream('hello', $stream, $this->config);

        $this->assertNull($response);
    }

    public function testWriteLargeStreamConfig()
    {
        $stream = fopen('data://text/plain;base64,'.base64_encode('world'), 'r');

        $config = $this->config
            ->extend(['swiftLargeObjectThreshold' => 104857600]) // 100 MiB
            ->extend(['swiftSegmentSize' => 52428800]) // 50 MiB
            ->extend(['swiftSegmentContainer' => 'segment-container']);

        $this->adapter = new SwiftAdapterStub($this->container);
        $this->adapter->streamMock = Mockery::mock(Stream::class);

        $this->adapter->streamMock
            ->shouldReceive('getSize')
            ->once()
            ->andReturn(209715200); // 200 MB

        $this->container->shouldReceive('createLargeObject')
            ->once()
            ->with([
                'name' => 'hello',
                'stream' => $this->adapter->streamMock,
                'segmentSize' => 52428800, // 50 MiB
                'segmentContainer' => 'segment-container',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->writeStream('hello', $stream, $config);

        $this->assertNull($response);
    }

    public function testMetadataMethods()
    {
        $methods = [
            'fileSize',
            'mimeType',
            'lastModified'
        ];

        $expect = [
            'path' => 'name',
            'type' => 'file',
            'last_modified' => 1628624822,
            'mime_type' => 'text/html; charset=UTF-8',
            'visibility' => null,
            'file_size' => 0,
            'extra_metadata' => [],
        ];

        foreach ($methods as $method) {
            $this->object->shouldReceive('retrieve')->once();

            $this->container->shouldReceive('getObject')
                ->once()
                ->with('hello')
                ->andReturn($this->object);

            $metadata = $this->adapter->$method('hello');

            $this->assertEquals($expect, $metadata->jsonSerialize());
        }
    }

    public function testSetVisibility()
    {
        $this->expectException(UnableToSetVisibility::class);
        $this->adapter->setVisibility('hello', 'public');
    }

    public function testVisibility()
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->adapter->visibility('hello');
    }
}

class SwiftAdapterStub extends SwiftAdapter
{
    public $streamMock;

    protected function getStreamFromResource($resource, array $options = []): Stream
    {
        return $this->streamMock;
    }
}

<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use Mockery\LegacyMockInterface;
use Nimbusoft\Flysystem\OpenStack\SwiftAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use org\bovigo\vfs\content\LargeFileContent;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
final class SwiftAdapterTest extends TestCase
{
    private SwiftAdapter $adapter;

    private Config $config;

    private LegacyMockInterface $container;

    private LegacyMockInterface $object;

    private LegacyMockInterface $streamFactory;

    protected function setUp(): void
    {
        $this->config = new Config([]);
        $this->container = Mockery::mock('OpenStack\ObjectStore\v1\Models\Container');
        $this->container->name = 'container-name';
        $this->object = Mockery::mock('OpenStack\ObjectStore\v1\Models\StorageObject');
        $this->streamFactory = Mockery::mock(StreamFactoryInterface::class);

        // Object properties.
        $this->object->name = 'name';
        $this->object->contentType = 'text/html; charset=UTF-8';
        $this->object->lastModified = 1628624822;

        $this->adapter = new SwiftAdapter($this->container, $this->streamFactory);

        // for testing the large object support
        $this->root = vfsStream::setUp('home');
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

        self::assertNull($response);
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

        self::assertNull($response);
    }

    public function testFileExists()
    {
        $this->container
            ->shouldReceive('objectExists')
            ->once()
            ->with('hello')
            ->andReturn(true);

        $fileExists = $this->adapter->fileExists('hello');

        self::assertTrue($fileExists);
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
                'prefix' => 'hello',
            ])
            ->andReturn($objects);

        $contents = array_map(
            static function (FileAttributes $fileAttributes): array {
                return $fileAttributes->jsonSerialize();
            },
            iterator_to_array($this->adapter->listContents('hello', false))
        );

        for ($i = 1; $i <= $times; ++$i) {
            $data[] = [
                'path' => 'name',
                'type' => 'file',
                'last_modified' => 1628624822,
                'mime_type' => 'text/html; charset=UTF-8',
                'visibility' => null,
                'file_size' => 0,
                'extra_metadata' => [
                    'dirname' => 'name',
                    'type' => 'file',
                ],
            ];
        }

        self::assertEquals($data, $contents);
    }

    public function testMove()
    {
        $this->object->shouldReceive('retrieve')->once();
        $this->object->shouldReceive('copy')->once()->with([
            'destination' => '/container-name/world',
        ]);
        $this->object->shouldReceive('delete')->once();

        $this->container->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $response = $this->adapter->move('hello', 'world', $this->config);

        self::assertNull($response);
    }

    public function testRead()
    {
        $stream = Mockery::mock('GuzzleHttp\Psr7\Stream');
        $stream->shouldReceive('close');
        $stream->shouldReceive('rewind');
        $stream->shouldReceive('getContents')->once()->andReturn('hello world');

        $this->object->shouldReceive('retrieve')->once();
        $this->object->shouldReceive('download')
            ->once()
            ->andReturn($stream);

        $this->container
            ->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $data = $this->adapter->read('hello');

        self::assertEquals($data, 'hello world');
    }

    public function testReadStream()
    {
        $resource = fopen('data://text/plain;base64,' . base64_encode('world'), 'rb');
        $psrStream = (new Psr17Factory())->createStreamFromResource($resource);

        $this->object->shouldReceive('retrieve')->once();
        $this->object->shouldReceive('download')
            ->once()
            ->andReturn($psrStream);

        $this->container
            ->shouldReceive('getObject')
            ->once()
            ->with('hello')
            ->andReturn($this->object);

        $data = $this->adapter->readStream('hello');

        self::assertEquals('world', stream_get_contents($data));
    }

    public function testWrite()
    {
        $this
            ->container
            ->shouldReceive('createObject')
            ->once()
            ->with([
                'name' => 'hello',
                'content' => 'world',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->write('hello', 'world', $this->config);

        self::assertNull($response);
    }

    public function testWriteAndUpdateLargeStreamConfig()
    {
        $config = $this
            ->config
            ->extend(['swiftLargeObjectThreshold' => 104857600]) // 100 MiB
            ->extend(['swiftSegmentSize' => 52428800]) // 50 MiB
            ->extend(['swiftSegmentContainer' => 'segmentContainer']);

        vfsStream::newFile('large.txt')
            ->withContent(LargeFileContent::withMegabytes(200))
            ->at($this->root);

        $stream = fopen(vfsStream::url('home/large.txt'), 'rb');
        $psrStream = (new Psr17Factory())->createStreamFromResource($stream);

        $this
            ->streamFactory
            ->shouldReceive('createStreamFromResource')
            ->once()
            ->with($stream)
            ->andReturn($psrStream);

        $this
            ->container
            ->shouldReceive('createLargeObject')
            ->once()
            ->with([
                'name' => 'hello',
                'stream' => $psrStream,
                'segmentSize' => 52428800, // 50 MiB
                'segmentContainer' => 'segmentContainer',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->writeStream('hello', $stream, $config);

        self::assertNull($response);
    }

    public function testWriteResource()
    {
        $stream = fopen('data://text/plain;base64,' . base64_encode('world'), 'rb');
        $psrStream = (new Psr17Factory())->createStreamFromResource($stream);

        $this
            ->streamFactory
            ->shouldReceive('createStreamFromResource')
            ->once()
            ->with($stream)
            ->andReturn($psrStream);

        $this
            ->container
            ->shouldReceive('createLargeObject')
            ->once()
            ->with([
                'name' => 'hello',
                'stream' => $psrStream,
                'segmentSize' => 104857600,
                'segmentContainer' => 'container-name',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->writeStream('hello', $stream, $this->config);

        self::assertNull($response);
    }

    public function testWriteStream()
    {
        vfsStream::newFile('large.txt')
            ->withContent(LargeFileContent::withMegabytes(400))
            ->at($this->root);

        $stream = fopen(vfsStream::url('home/large.txt'), 'rb');
        $psrStream = (new Psr17Factory())->createStreamFromResource($stream);

        $this
            ->streamFactory
            ->shouldReceive('createStreamFromResource')
            ->once()
            ->with($stream)
            ->andReturn($psrStream);

        $this
            ->container
            ->shouldReceive('createLargeObject')
            ->once()
            ->with([
                'name' => 'hello',
                'stream' => $psrStream,
                'segmentSize' => 104857600, // 100 MiB
                'segmentContainer' => 'container-name',
            ])
            ->andReturn($this->object);

        $response = $this->adapter->writeStream('hello', $stream, $this->config);

        self::assertNull($response);
    }
}

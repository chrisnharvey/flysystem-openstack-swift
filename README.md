# Flysystem Adapter for OpenStack Swift

[![Author](http://img.shields.io/badge/author-@chrisnharvey-blue.svg)](https://twitter.com/chrisnharvey)
[![Tests](https://github.com/chrisnharvey/flysystem-openstack-swift/actions/workflows/tests.yml/badge.svg)](https://github.com/chrisnharvey/flysystem-openstack-swift/actions/workflows/tests.yml)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/nimbusoft/flysystem-openstack-swift.svg)](https://packagist.org/packages/nimbusoft/flysystem-openstack-swift)
[![Total Downloads](https://img.shields.io/packagist/dt/nimbusoft/flysystem-openstack-swift.svg)](https://packagist.org/packages/nimbusoft/flysystem-openstack-swift)

Flysystem adapter for OpenStack Swift.

## Installation

```bash
composer require nimbusoft/flysystem-openstack-swift
```
## Usage

```php
$openstack = new OpenStack\OpenStack([
    'authUrl' => '{authUrl}',
    'region'  => '{region}',
    'user'    => [
        'id'       => '{userId}',
        'password' => '{password}'
    ],
    'scope'   => ['project' => ['id' => '{projectId}']]
]);

$container = $openstack->objectStoreV1()
    ->getContainer('{containerName}');

$adapter = new Nimbusoft\Flysystem\OpenStack\SwiftAdapter($container);

$flysystem = new League\Flysystem\Filesystem($adapter);
```

## Configuration

The Swift adapter has the following configuration options:

### Uploading large objects
See more at [openstack documentation](https://php-opencloudopenstack.readthedocs.io/en/latest/services/object-store/v1/objects.html#create-a-large-object-over-5gb)
- `swiftLargeObjectThreshold`: Size of the file in bytes when to switch over to the large object upload procedure. Default is 300 MiB. The maximum allowed size of regular objects is 5 GiB.
- `swiftSegmentSize`: Size of individual segments or chunks that the large file is split up into. Default is 100 MiB. Should be below 5 GiB.
- `swiftSegmentContainer`: Name of the Swift container to store the large object segments to. Default is the same container that stores the regular files.

### Content type
- `contentType`: Sets the Content-Type header of the request.
- `detectContentType`: If set to true, Object Storage guesses the content type based on the file extension and ignores the value sent in the Content-Type header, if present.

### File expiration
- `deleteAfter`: Specifies the number of seconds after which the object is removed. Internally, the Object Storage system stores this value in the X-Delete-At metadata item.
- `deleteAt`: The certain date, in the UNIX Epoch timestamp format, when the object will be removed.


### Examples
These options can be set on `Filesystem` creation:

```php
$flysystem = new League\Flysystem\Filesystem($adapter, new \League\Flysystem\Config([
    'swiftLargeObjectThreshold' => 104857600, // 100 MiB
    'swiftSegmentSize' => 52428800, // 50 MiB
    'swiftSegmentContainer' => 'mySegmentContainer',
]));
```

or per-file:

```php
$flysystem->write($path, $contents, new \League\Flysystem\Config([
    'swiftLargeObjectThreshold' => 52428800, // 50 MiB
    'contentType' => 'text/plain',
    'deleteAfter' => 3600, // 1 hour
])
```

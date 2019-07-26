# Flysystem Adapter for OpenStack Swift

[![Author](http://img.shields.io/badge/author-@chrisnharvey-blue.svg?style=flat-square)](https://twitter.com/chrisnharvey)
[![Build Status](https://img.shields.io/travis/nimbusoftltd/flysystem-openstack-swift/master.svg?style=flat-square)](https://travis-ci.org/nimbusoftltd/flysystem-openstack-swift)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/nimbusoft/flysystem-openstack-swift.svg?style=flat-square)](https://packagist.org/packages/nimbusoft/flysystem-openstack-swift)
[![Total Downloads](https://img.shields.io/packagist/dt/nimbusoft/flysystem-openstack-swift.svg?style=flat-square)](https://packagist.org/packages/nimbusoft/flysystem-openstack-swift)

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

The Swift adapter allows you to configure the behavior of uploading [large objects](https://php-opencloudopenstack.readthedocs.io/en/latest/services/object-store/v1/objects.html#create-a-large-object-over-5gb). You can set the following configuration options:

- `swiftLargeObjectThreshold`: Size of the file in bytes when to switch over to the large object upload procedure. Default is 300 MiB. The maximum allowed size of regular objects is 5 GiB.
- `swiftSegmentSize`: Size of individual segments or chunks that the large file is split up into. Default is 100 MiB. Should be below 5 GiB.
- `swiftSegmentContainer`: Name of the Swift container to store the large object segments to. Default is the same container that stores the regular files.

Example:

```php

$flysystem = new League\Flysystem\Filesystem($adapter, new \League\Flysystem\Config([
    'swiftLargeObjectThreshold' => 104857600, // 100 MiB
    'swiftSegmentSize' => 52428800, // 50 MiB
    'swiftSegmentContainer' => 'mySegmentContainer',
]));
```

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

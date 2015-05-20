# Flysystem stream wrapper

[![Author](http://img.shields.io/badge/author-@chrisleppanen-blue.svg?style=flat-square)](https://twitter.com/chrisleppanen)
[![Build Status](https://img.shields.io/travis/twistor/flysystem-stream-wrapper/master.svg?style=flat-square)](https://travis-ci.org/twistor/flysystem-stream-wrapper)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/twistor/flysystem-stream-wrapper.svg?style=flat-square)](https://scrutinizer-ci.com/g/twistor/flysystem-stream-wrapper/code-structure)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/twistor/flysystem-stream-wrapper.svg?style=flat-square)](https://packagist.org/packages/twistor/flysystem-stream-wrapper)

## Installation

```
composer require twistor/flysystem-stream-wrapper
```

## Usage

```php
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

// Get a Filesystem object.
$filesystem = new Filesystem(new Local('/some/path'));

FlysystemStreamWrapper::register('fly', $filesystem);

// Then you can use it like so.
file_put_contents('fly://filename.txt', $content);

mkdir('fly://happy_thoughts');

FlysystemStreamWrapper::unregister('fly');

```

## Notes

This project tries to emulate the behavior of the standard PHP functions,
rename(), mkdir(), unlink(), etc., as closely as possible. This includes
emitting wanrings. If any differences are discovered, please file an issue.

<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;
use Twistor\Tests\WritableLocal;

class WritableOptimzedTest  extends FlysystemStreamWrapperTest
{
    public function setUp()
    {
        $this->testDir = __DIR__ . '/testdir';

        $filesystem = new Filesystem(new Local(__DIR__));
        $filesystem->deleteDir('testdir');
        $filesystem->createDir('testdir');

        $this->filesystem = new Filesystem(new WritableLocal($this->testDir, 'r+'));
        FlysystemStreamWrapper::register('flysystem', $this->filesystem);
    }
}

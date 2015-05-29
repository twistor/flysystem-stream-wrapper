<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;
use Twistor\Tests\NoVisibilityLocal;

class FlysystemStreamWrapperTest extends \PHPUnit_Framework_TestCase
{
    public function testRegister()
    {
        $filesystem = new Filesystem(new NullAdapter());
        $this->assertTrue(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(in_array('test', stream_get_wrappers(), true));

        // Registering twice should be a noop.
        $this->assertFalse(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(FlysystemStreamWrapper::unregister('test'));
        $this->assertFalse(FlysystemStreamWrapper::unregister('test'));
    }

    public function testNoVisibility()
    {
        $filesystem = new Filesystem(new NoVisibilityLocal(sys_get_temp_dir()));
        FlysystemStreamWrapper::register('vis', $filesystem);
        $this->assertTrue(touch('vis://test.txt'));
        $this->assertTrue(chmod('vis://test.txt', 0777));
        $filesystem->delete('test.txt');
    }
}

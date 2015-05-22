<?php

namespace Twistor\Tests;

use Twistor\Flysystem\Plugin\Stat;

class StatTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $plugin = new Stat();
        $filesystem = $this->prophesize('League\Flysystem\Filesystem');
        $plugin->setFilesystem($filesystem->reveal());

        $filesystem->getWithMetadata('path', ['timestamp', 'size'])->willReturn(false);
        $this->assertInternalType('array', $plugin->handle('path', 1));
    }
}

<?php

namespace Twistor\Tests;

use Prophecy\PhpUnit\ProphecyTestCase;
use Twistor\Flysystem\Plugin\Stat;

class StatTest extends ProphecyTestCase
{
    public function test()
    {
        $plugin = new Stat();
        $filesystem = $this->prophesize('League\Flysystem\Filesystem');
        $plugin->setFilesystem($filesystem->reveal());

        $filesystem->getWithMetadata('path', ['timestamp', 'size', 'visibility'])->willReturn(false);
        $this->assertInternalType('array', $plugin->handle('path', 1));
    }
}

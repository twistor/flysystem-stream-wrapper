<?php

namespace Twistor\Tests;

use Prophecy\PhpUnit\ProphecyTestCase;
use Twistor\Flysystem\Plugin\Stat;

class StatTest extends ProphecyTestCase
{
    public function test()
    {
        $permissions = [
            'dir' => [
                'private' => 0700,
                'public' => 0744,
            ],
            'file' => [
                'private' => 0700,
                'public' => 0744,
            ],
        ];

        $metadata = ['size'];

        $plugin = new Stat($permissions, $metadata);
        $filesystem = $this->prophesize('League\Flysystem\Filesystem');
        $plugin->setFilesystem($filesystem->reveal());

        $filesystem->getMetadata('path')->willReturn(false);
        $this->assertInternalType('array', $plugin->handle('path', 1));
    }
}

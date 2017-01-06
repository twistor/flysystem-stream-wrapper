<?php

use League\Flysystem\Filesystem;
use Twistor\Flysystem\Plugin\Stat;

class StatTest extends \PHPUnit_Framework_TestCase
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

        $metadata = ['size', 'timestamp', 'visibility'];

        $plugin = new Stat($permissions, $metadata);
        $filesystem = $this->prophesize(Filesystem::class);
        $plugin->setFilesystem($filesystem->reveal());

        $filesystem->getMetadata('path')->willReturn(false);
        $this->assertInternalType('array', $plugin->handle('path', 1));

        $filesystem->getMetadata('path2')->willReturn(['size' => 10, 'timestamp' => time(), 'type' => 'file']);
        $filesystem->getVisibility('path2')->willThrow(new \LogicException());
        $this->assertSame(0100744, $plugin->handle('path2', 1)['mode']);

        // Check that getVisibility() doesn't get called again.
        $filesystem->getMetadata('path3')->willReturn(['size' => 10, 'timestamp' => time(), 'type' => 'file']);
        $filesystem->getVisibility('path3')->willThrow(new \Exception());
        $this->assertSame(0100744, $plugin->handle('path3', 1)['mode']);
    }
}

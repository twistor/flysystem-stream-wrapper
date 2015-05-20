<?php

namespace Twistor\Tests;

use League\Flysystem\Filesystem;
use Twistor\Flysystem\Plugin\ForcedRename;

class ForcedRenameTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $plugin = new ForcedRename();
        $adapter = $this->prophesize('League\Flysystem\AdapterInterface');
        $plugin->setFilesystem(new Filesystem($adapter->reveal()));

        $adapter->has('source')->willReturn(true);
        $adapter->has('dest')->willReturn(true);
        $adapter->getMetadata('source')->willReturn(['type' => 'file']);
        $adapter->getMetadata('dest')->willReturn(['type' => 'file']);
        $adapter->delete('dest')->willReturn(false);

        $this->assertFalse($plugin->handle('source', 'dest'));
    }
}

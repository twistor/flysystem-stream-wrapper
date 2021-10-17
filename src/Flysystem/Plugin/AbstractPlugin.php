<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Plugin\AbstractPlugin as FlysystemPlugin;

abstract class AbstractPlugin
{
    /**
     * @var \League\Flysystem\Filesystem
     */
    protected $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }
    
}

<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\Config;
use League\Flysystem\Plugin\AbstractPlugin as FlysystemPlugin;

abstract class AbstractPlugin extends FlysystemPlugin
{
    protected function defaultConfig()
    {
        $config = new Config();
        $config->setFallback($this->filesystem->getConfig());

        return $config;
    }
}

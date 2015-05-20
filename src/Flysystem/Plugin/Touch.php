<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\Config;
use League\Flysystem\Plugin\AbstractPlugin;
use League\Flysystem\Util;

class Touch extends AbstractPlugin
{
    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'touch';
    }

    /**
     * Emulates touch().
     *
     * @param string $path
     *
     * @return bool True on success, false on failure.
     */
    public function handle($path)
    {
        $path = Util::normalizePath($path);
        $adapter = $this->filesystem->getAdapter();

        if ($adapter->has($path)) {
            return true;
        }

        $config = new Config();
        $config->setFallback($this->filesystem->getConfig());

        return (bool) $adapter->write($path, '', $config);
    }
}

<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Plugin\AbstractPlugin;
use League\Flysystem\Util;

class Mkdir extends AbstractPlugin
{
    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'mkdir';
    }

    /**
     * Creates a directory.
     *
     * @param string $dirname
     * @param int    $mode
     * @param int    $options
     *
     * @return bool True on success, false on failure.
     */
    public function handle($dirname, $mode, $options)
    {
        $dirname = Util::normalizePath($dirname);
        $config = new Config();
        $config->setFallback($this->filesystem->getConfig());

        $adapter = $this->filesystem->getAdapter();

        // If recursive, or a single level directory, just create it.
        if (($options & STREAM_MKDIR_RECURSIVE) || strpos($dirname, '/') === false) {
            return (bool) $adapter->createDir($dirname, $config);
        }

        if (!$adapter->has(dirname($dirname))) {
            throw new FileNotFoundException($dirname);
        }

        return (bool) $adapter->createDir($dirname, $config);
    }
}

<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\Plugin\AbstractPlugin;
use League\Flysystem\Util;

class ForcedRename extends AbstractPlugin
{
    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'forcedRename';
    }

    /**
     * Renames a file.
     *
     * @param string $path    path to file
     * @param string $newpath new path
     *
     * @throws FileNotFoundException
     *
     * @return bool success boolean
     */
    public function handle($path, $newpath)
    {
        $path = Util::normalizePath($path);
        $newpath = Util::normalizePath($newpath);
        $this->filesystem->assertPresent($path);

        // Ignore useless renames.
        if ($path === $newpath) {
            return true;
        }

        return (bool) $this->filesystem->getAdapter()->rename($path, $newpath);
    }
}

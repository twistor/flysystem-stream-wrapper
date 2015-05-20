<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use Twistor\Flysystem\Exception\DirectoryExistsException;
use Twistor\Flysystem\Exception\DirectoryNotEmptyException;

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
     * @throws \League\Flysystem\FileNotFoundException
     * @throws \League\Flysystem\FileExistsException
     * @throws \Twistor\Flysystem\Exception\DirectoryExistsException
     * @throws \Twistor\Flysystem\Exception\DirectoryNotEmptyException
     *
     * @return bool
     */
    public function handle($path, $newpath)
    {
        $path = Util::normalizePath($path);
        $newpath = Util::normalizePath($newpath);

        // Ignore useless renames.
        if ($path === $newpath) {
            return true;
        }

        $this->assertValidRename($path, $newpath);

        return (bool) $this->filesystem->getAdapter()->rename($path, $newpath);
    }

    protected function assertValidRename($source, $dest)
    {
        $adapter = $this->filesystem->getAdapter();

        if (!$adapter->has($source)) {
            throw new FileNotFoundException($source);
        }

        if (!$adapter->has($dest)) {
            if (!$adapter->has(dirname($dest))) {
                throw new FileNotFoundException($source);
            }
            return;
        }

        $this->checkMetadata($source, $dest);
    }

    protected function checkMetadata($source, $dest)
    {
        $adapter = $this->filesystem->getAdapter();

        $source_meta = $adapter->getMetadata($source);
        $dest_meta = $adapter->getMetadata($dest);

        if ($dest_meta['type'] === 'dir') {
            if (!empty($contents = $this->filesystem->listContents($dest))) {
                throw new DirectoryNotEmptyException();
            }
        }

        if ($source_meta['type'] === $dest_meta['type']) {
            return;
        }

        if ($dest_meta['type'] === 'dir') {
            throw new DirectoryExistsException();
        }

        throw new FileExistsException($dest);
    }
}

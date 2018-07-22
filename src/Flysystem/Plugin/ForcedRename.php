<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use Twistor\Flysystem\Exception\DirectoryExistsException;
use Twistor\Flysystem\Exception\DirectoryNotEmptyException;
use Twistor\Flysystem\Exception\NotADirectoryException;

class ForcedRename extends AbstractPlugin
{
    /**
     * @inheritdoc
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
     * @return bool
     *
     * @throws \League\Flysystem\FileNotFoundException
     * @throws \Twistor\Flysystem\Exception\DirectoryExistsException
     * @throws \Twistor\Flysystem\Exception\DirectoryNotEmptyException
     * @throws \Twistor\Flysystem\Exception\NotADirectoryException
     */
    public function handle($path, $newpath)
    {
        $path = Util::normalizePath($path);
        $newpath = Util::normalizePath($newpath);

        // Ignore useless renames.
        if ($path === $newpath) {
            return true;
        }

        if ( ! $this->isValidRename($path, $newpath)) {
            // Returns false if a Flysystem call fails.
            return false;
        }

        return (bool) $this->filesystem->getAdapter()->rename($path, $newpath);
    }

    /**
     * Checks that a rename is valid.
     *
     * @param string $source
     * @param string $dest
     *
     * @return bool
     *
     * @throws \League\Flysystem\FileNotFoundException
     * @throws \Twistor\Flysystem\Exception\DirectoryExistsException
     * @throws \Twistor\Flysystem\Exception\DirectoryNotEmptyException
     * @throws \Twistor\Flysystem\Exception\NotADirectoryException
     */
    protected function isValidRename($source, $dest)
    {
        $adapter = $this->filesystem->getAdapter();

        if ( ! $adapter->has($source)) {
            throw new FileNotFoundException($source);
        }

        $subdir = Util::dirname($dest);

        if (strlen($subdir) && ! $adapter->has($subdir)) {
            throw new FileNotFoundException($source);
        }

        if ( ! $adapter->has($dest)) {
            return true;
        }

        return $this->compareTypes($source, $dest);
    }

    /**
     * Compares the file/dir for the source and dest.
     *
     * @param string $source
     * @param string $dest
     *
     * @return bool
     *
     * @throws \Twistor\Flysystem\Exception\DirectoryExistsException
     * @throws \Twistor\Flysystem\Exception\DirectoryNotEmptyException
     * @throws \Twistor\Flysystem\Exception\NotADirectoryException
     */
    protected function compareTypes($source, $dest)
    {
        $adapter = $this->filesystem->getAdapter();

        $source_type = $adapter->getMetadata($source)['type'];
        $dest_type = $adapter->getMetadata($dest)['type'];

        // These three checks are done in order of cost to minimize Flysystem
        // calls.

        // Don't allow overwriting different types.
        if ($source_type !== $dest_type) {
            if ($dest_type === 'dir') {
                throw new DirectoryExistsException();
            }

            throw new NotADirectoryException();
        }

        // Allow overwriting destination file.
        if ($source_type === 'file') {
            return $adapter->delete($dest);
        }

        // Allow overwriting destination directory if not empty.
        $contents = $this->filesystem->listContents($dest);
        if ( ! empty($contents)) {
            throw new DirectoryNotEmptyException();
        }

        return $adapter->deleteDir($dest);
    }
}

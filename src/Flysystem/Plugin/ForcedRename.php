<?php

namespace Twistor\Flysystem\Plugin;

use Twistor\Flysystem\Exception\DirectoryExistsException;
use Twistor\Flysystem\Exception\DirectoryNotEmptyException;
use Twistor\Flysystem\Exception\FileNotFoundException;
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
        // Ignore useless renames.
        if ($path === $newpath) {
            return true;
        }

        if ( ! $this->isValidRename($path, $newpath)) {
            // Returns false if a Flysystem call fails.
            return false;
        }

        return (bool) $this->filesystem->move($path, $newpath);
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
        if ( ! $this->filesystem->fileExists($source)) {
            throw new FileNotFoundException($source);
        }

        $subdir = dirname($dest);

        if (strlen($subdir) && $subdir != '.' && ! $this->filesystem->fileExists($subdir)) {
            throw new FileNotFoundException($subdir);
        }

        if ( ! $this->filesystem->fileExists($dest)) {
            return true;
        }

        return false;
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
     * @todo Check if still needed
     */
    protected function compareTypes($source, $dest)
    {

        $source_type = $this->filesystem->mimeType($source);
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

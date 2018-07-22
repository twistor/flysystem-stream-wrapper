<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\RootViolationException;
use League\Flysystem\Util;
use Twistor\Flysystem\Exception\DirectoryNotEmptyException;

class Rmdir extends AbstractPlugin
{
    /**
     * @inheritdoc
     */
    public function getMethod()
    {
        return 'rmdir';
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname path to directory
     * @param int $options
     *
     * @return bool
     *
     * @throws \Twistor\Flysystem\Exception\DirectoryNotEmptyException
     */
    public function handle($dirname, $options)
    {
        $dirname = Util::normalizePath($dirname);

        if ($dirname === '') {
            throw new RootViolationException('Root directories can not be deleted.');
        }

        $adapter = $this->filesystem->getAdapter();

        if ($options & STREAM_MKDIR_RECURSIVE) {
            // I don't know how this gets triggered.
            return (bool) $adapter->deleteDir($dirname);
        }

        $contents = $this->filesystem->listContents($dirname);

        if ( ! empty($contents)) {
            throw new DirectoryNotEmptyException();
        }

        return (bool) $adapter->deleteDir($dirname);
    }
}

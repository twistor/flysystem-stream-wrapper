<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\FileExistsException;
use League\Flysystem\Plugin\AbstractPlugin;
use League\Flysystem\RootViolationException;
use League\Flysystem\Util;

class Rmdir extends AbstractPlugin
{
    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'rmdir';
    }
    /**
     * Delete a directory.
     *
     * @param string $dirname path to directory
     * @param int    $options
     *
     * @return bool
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
            return (bool) $adapter->deleteDir($dirname); // @codeCoverageIgnore
        }

        $contents = $this->filesystem->listContents($dirname);

        if (!empty($contents)) {
            throw new FileExistsException();
        }

        return (bool) $adapter->deleteDir($dirname);
    }
}

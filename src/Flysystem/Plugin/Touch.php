<?php

namespace Twistor\Flysystem\Plugin;

class Touch extends AbstractPlugin
{
    /**
     * Emulates touch().
     *
     * @param string $path
     *
     * @return bool True on success, false on failure.
     */
    public function handle($path)
    {
        if ($this->filesystem->fileExists($path)) {
            return true;
        }

        return (bool) $this->filesystem->write($path, '');
    }
}

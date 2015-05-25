<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\AdapterInterface;

class Stat extends AbstractPlugin
{
    /**
     * Default return value of url_stat().
     *
     * @var array
     */
    protected static $defaultMeta = [
        'dev' => 0,
        'ino' => 0,
        'mode' => 0,
        'nlink' => 0,
        'uid' => 0,
        'gid' => 0,
        'rdev' => 0,
        'size' => 0,
        'atime' => 0,
        'mtime' => 0,
        'ctime' => 0,
        'blksize' => -1,
        'blocks' => -1,
    ];

    /**
     * Required metadata.
     *
     * @var array
     */
    protected static $required = ['timestamp', 'size', 'visibility'];

    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'stat';
    }

    /**
     * Emulates stat().
     *
     * @param string $path
     * @param int    $flags
     *
     * @return array Output similar to stat().
     *
     * @see stat()
     */
    public function handle($path, $flags)
    {
        $metadata = $this->filesystem->getWithMetadata($path, static::$required);

        // It's possible for getMetadata() to fail even if a file exists.
        // Is the correct way to handle this?
        if ($metadata === false) {
            return static::$defaultMeta;
        }

        return $this->mergeMeta($metadata + ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
    }

    /**
     * Merges the available metadata from Filesystem::getMetadata().
     *
     * @param array $metadata The metadata.
     *
     * @return array All metadata with default values filled in.
     */
    protected function mergeMeta(array $metadata)
    {
        $ret = static::$defaultMeta;

        $ret['mode'] = $metadata['type'] === 'dir' ? 040000 : 0100000;
        $ret['mode'] += $metadata['visibility'] === AdapterInterface::VISIBILITY_PRIVATE ? 0700 : 0744;

        if (isset($metadata['size'])) {
            $ret['size'] = (int) $metadata['size'];
        }
        if (isset($metadata['timestamp'])) {
            $ret['mtime'] = (int) $metadata['timestamp'];
            $ret['ctime'] = (int) $metadata['timestamp'];
        }

        $ret['atime'] = time();

        return array_merge(array_values($ret), $ret);
    }
}

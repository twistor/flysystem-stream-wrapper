<?php

namespace Twistor\Flysystem\Plugin;

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
        $metadata = $this->filesystem->getMetadata($path);

        // It's possible for getMetadata() to fail even if a file exists.
        // @todo Figure out the correct way to handle this.
        if ($metadata === false) {
            return static::$defaultMeta; // @codeCoverageIgnore
        }

        return $this->mergeMeta($metadata);
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

        // Dirs are 0777. Files are 0666.
        $ret['mode'] = $metadata['type'] === 'dir' ? 040777 : 0100664;

        if (isset($metadata['size'])) {
            $ret['size'] = $metadata['size'];
        }
        if (isset($metadata['timestamp'])) {
            $ret['mtime'] = $metadata['timestamp'];
            $ret['ctime'] = $metadata['timestamp'];
        }

        $ret['atime'] = time();

        return array_merge(array_values($ret), $ret);
    }
}

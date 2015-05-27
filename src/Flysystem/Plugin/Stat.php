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
     * Permission map.
     *
     * @var array
     */
    protected $permissions;

    /**
     * Required metadata.
     *
     * @var array
     */
    protected $required;

    /**
     * Constructs a Stat object.
     *
     * @param array $permissions An array of permissions.
     * @param array $metadata    The default required metadata.
     */
    public function __construct(array $permissions, array $metadata)
    {
        $this->permissions = $permissions;
        $this->required = $metadata;
    }

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
        $metadata = $this->getWithMetadata($path);

        // It's possible for getMetadata() to fail even if a file exists.
        if (empty($metadata)) {
            return static::$defaultMeta;
        }

        return $this->mergeMeta($metadata + ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
    }

    /**
     * Returns metadata.
     *
     * @param string $path
     *
     * @return array
     */
    protected function getWithMetadata($path)
    {
        $metadata = $this->filesystem->getMetadata($path);

        if (empty($metadata)) {
            return [];
        }

        $keys = array_diff($this->required, array_keys($metadata));

        foreach ($keys as $key) {
            $method = 'get' . ucfirst($key);

            try {
                $metadata[$key] = $this->filesystem->$method($path);
            } catch (\Exception $e) {
                // Some adapters don't support certain metadata. For instance,
                // the Dropbox adapter throws exceptions when calling
                // getVisibility(). We should figure out a better way to detect
                // this. Catching exceptions is messy business.
            }
        }

        return $metadata;
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
        $ret['mode'] += $this->permissions[$metadata['type']][$metadata['visibility']];

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

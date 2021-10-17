<?php

namespace Twistor\Flysystem\Plugin;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use Twistor\FlysystemStreamWrapper;
use Twistor\PosixUid;
use Twistor\Uid;

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
     * @var \Twistor\Uid
     */
    protected $uid;

    /**
     * Constructs a Stat object.
     *
     * @param array $permissions An array of permissions.
     * @param array $metadata    The default required metadata.
     */
    public function __construct(Filesystem $fileSystem, array $permissions, array $metadata)
    {
        parent::__construct($fileSystem);
        
        $this->permissions = $permissions;
        $this->required = array_combine($metadata, $metadata);
        $this->uid = \extension_loaded('posix') ? new PosixUid() : new Uid();
    }

    /**
     * @inheritdoc
     */
    public function getMethod()
    {
        return 'stat';
    }

    /**
     * Emulates stat().
     *
     * @param string $path
     * @param int $flags
     *
     * @return array Output similar to stat().
     *
     * @throws \League\Flysystem\FileNotFoundException
     *
     * @see stat()
     */
    public function handle($path, $flags)
    {
        if ($path === '') {
            return $this->mergeMeta([
                'type' => 'dir', 
                'visibility' => 'public'
            ]);
        }

        $ignore = $flags & FlysystemStreamWrapper::STREAM_URL_IGNORE_SIZE ? ['size'] : [];

        if($this->filesystem->fileExists($path) === false && $this->filesystem->mimeType($path) !== 'directory') {
            return false;
        }

        try {
            $metadata = $this->getWithMetadata($path, $ignore);

        } catch (\Exception $exp) {
            $this->mergeMeta([
                'type' => 'dir',
                'visibility' => 'public'
            ]);
        }
        
        // It's possible for getMetadata() to fail even if a file exists.
        if (empty($metadata)) {
            return static::$defaultMeta;
        }

        return $this->mergeMeta($metadata + ['visibility' => 'public']);
    }

    /**
     * Returns metadata.
     *
     * @param string $path The path to get metadata for.
     * @param array $ignore Metadata to ignore.
     *
     * @return array The metadata as returned by Filesystem::getMetadata().
     *
     * @throws \League\Flysystem\FileNotFoundException
     *
     * @see \League\Flysystem\Filesystem::getMetadata()
     */
    protected function getWithMetadata($path, array $ignore)
    {
        // $metadata = $this->filesystem->getMetadata($path);

        $metadata = [];
        $metadata['type'] = $this->filesystem->mimeType($path);
        $metadata['timestamp'] = $this->filesystem->lastModified($path);

        try {
            $metadata['size'] = $this->filesystem->fileSize($path);
        } catch (\Exception $exp) {
            $metadata['size'] = 0;
        }
        
        try {
            $metadata['visibility'] = $this->filesystem->visibility($path);
        } catch (\Exception $exp) {
            $metadata['visibility'] = [];
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

        $ret['uid'] = $this->uid->getUid();
        $ret['gid'] = $this->uid->getGid();

        $ret['mode'] = $metadata['type'] === 'directory' ? 040000 : 0100000;
        $ret['mode'] += empty($this->permissions[$metadata['type']][$metadata['visibility']]) ? intval($metadata['visibility'], 8) : $this->permissions[$metadata['type']][$metadata['visibility']];

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

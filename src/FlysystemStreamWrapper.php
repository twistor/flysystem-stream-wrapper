<?php

namespace Twistor;

use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\RootViolationException;
use League\Flysystem\Util;

/**
 * An adapter for Flysystem to a PHP stream wrapper.
 */
class FlysystemStreamWrapper
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
     * The registered filesystems.
     *
     * @var \League\Flysystem\FilesystemInterface[]
     */
    protected static $filesystems = [];

    /**
     * The filesystem of the current stream wrapper.
     *
     * @var \League\Flysystem\FilesystemInterface
     */
    protected $filesystem;

    /**
     * A generic resource handle.
     *
     * @var resource|bool
     */
    protected $handle;

    /**
     * Whether the handle is in append-only mode.
     *
     * @var bool
     */
    protected $isAppendOnly = false;

    /**
     * Whether this handle is copy-on-write.
     *
     * @var bool
     */
    protected $isCow = false;

    /**
     * Whether the handle is read-only.
     *
     * The stream returned from Flysystem may not actually be read-only, This
     * ensures read-only behavior.
     *
     * @var bool
     */
    protected $isReadOnly = false;

    /**
     * Whether the handle is write-only.
     *
     * @var bool
     */
    protected $isWriteOnly = false;

    /**
     * A directory listing.
     *
     * @var array
     */
    protected $listing;

    /**
     * Whether the handle should be flushed.
     *
     * @var bool
     */
    protected $needsFlush = false;

    /**
     * Instance URI (stream).
     *
     * A stream is referenced as "protocol://target".
     *
     * @var string
     */
    protected $uri;

    /**
     * Registers the stream wrapper protocol if not already registered.
     *
     * @param string                                $protocol   The protocol.
     * @param \League\Flysystem\FilesystemInterface $filesystem The filesystem.
     *
     * @return bool True if the protocal was registered, false if not.
     */
    public static function register($protocol, FilesystemInterface $filesystem)
    {
        if (static::streamWrapperExists($protocol)) {
            return false;
        }

        static::$filesystems[$protocol] = $filesystem;

        return stream_wrapper_register($protocol, __CLASS__);
    }

    /**
     * Unegisters a stream wrapper.
     *
     * @param string $protocol The protocol.
     *
     * @return bool True if the protocal was unregistered, false if not.
     */
    public static function unregister($protocol)
    {
        if (!static::streamWrapperExists($protocol)) {
            return false;
        }

        unset(static::$filesystems[$protocol]);

        return stream_wrapper_unregister($protocol);
    }

    /**
     * Determines if a protocol is registered.
     *
     * @param string $protocol The protocol to check.
     *
     * @return bool True if it is registered, false if not.
     */
    protected static function streamWrapperExists($protocol)
    {
        return in_array($protocol, stream_get_wrappers(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function dir_closedir()
    {
        unset($this->listing);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_opendir($uri, $options)
    {
        $this->uri = $uri;
        $this->listing = $this->getFilesystem()->listContents($this->getTarget());

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_readdir()
    {
        $current = current($this->listing);
        next($this->listing);

        return $current ? $current['path'] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_rewinddir()
    {
        reset($this->listing);
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($uri, $mode, $options)
    {
        $this->uri = $uri;

        $path = Util::normalizePath($this->getTarget());
        $filesystem = $this->getFilesystem();

        // If recursive, or a single level directory, just create it.
        if (($options & STREAM_MKDIR_RECURSIVE) || strpos($path, '/') === false) {
            return $filesystem->createDir($path);
        }

        if (!$filesystem->has(dirname($path))) {
            if ($this->reportErrors($options)) {
                trigger_error(sprintf('mkdir(%s): No such file or directory', $uri), E_USER_WARNING);
            }
            return false;
        }

        return $filesystem->createDir($path);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($uri_from, $uri_to)
    {
        $this->uri = $uri_from;

        // Use normalizePath() here so that we can compare them below.
        $path_from = Util::normalizePath($this->getTarget($uri_from));
        $path_to = Util::normalizePath($this->getTarget($uri_to));

        // Ignore useless renames.
        if ($path_from === $path_to) {
            return true;
        }

        return $this->doRename($path_from, $path_to);
    }

    /**
     * Performs a rename.
     *
     * @param string $path_from The source path.
     * @param string $path_to   The destination path.
     *
     * @return bool True if successful, false if not.
     */
    protected function doRename($path_from, $path_to)
    {
        try {
            return $this->getFilesystem()->rename($path_from, $path_to);

        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('rename(%s,%s): No such file or directory', $path_from, $path_to), E_USER_WARNING);

        } catch (FileExistsException $e) {
            // PHP's rename() will overwrite an existing file. Emulate that.
            if ($this->doUnlink($path_to)) {
                return $this->getFilesystem()->rename($path_from, $path_to);
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir($uri, $options)
    {
        $this->uri = $uri;
        $path = $this->getTarget();

        if ($options & STREAM_MKDIR_RECURSIVE) {
            // I don't know how this gets triggered.
            return $this->doRmdir($path, $options); // @codeCoverageIgnore
        }

        $contents = $this->getFilesystem()->listContents($path);
        if (empty($contents)) {
            return $this->doRmdir($path, $options);
        }

        if ($this->reportErrors($options)) {
            trigger_error(sprintf('rmdir(%s): Directory not empty', $this->uri), E_USER_WARNING);
        }

        return false;
    }

    /**
     * Deletes a directory recursively.
     *
     * @param string $path    The path to delete.
     * @param int    $options Bitwise options.
     *
     * @return bool True on success, false on failure.
     */
    protected function doRmdir($path, $options)
    {
        try {
            return $this->getFilesystem()->deleteDir($path);

        } catch (RootViolationException $e) {
            if ($this->reportErrors($options)) {
                trigger_error(sprintf('rmdir(%s): Cannot remove the root directory', $this->uri), E_USER_WARNING);
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_cast($cast_as)
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_close()
    {
        fclose($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_eof()
    {
        return feof($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_flush()
    {
        if (!$this->needsFlush) {
            return true;
        }
        // Calling putStream() will rewind our handle. flush() shouldn't change
        // the position of the file.
        $pos = ftell($this->handle);
        $success = $this->getFilesystem()->putStream($this->getTarget(), $this->handle);

        fseek($this->handle, $pos);

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_lock($operation)
    {
        return flock($this->handle, $operation);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_metadata($uri, $option, $value)
    {
        $this->uri = $uri;

        switch ($option) {
            case STREAM_META_ACCESS:
                // Emulate chmod() since lots of things depend on it.
                // @todo We could do better with the emulation.
                return true;

            case STREAM_META_TOUCH:
                return $this->touch($this->getTarget());

            default:
                return false;
        }
    }

    /**
     * Emulates touch().
     *
     * @param string $path The path to touch.
     *
     * @return bool True if successful, false if not.
     */
    protected function touch($path)
    {
        $filesystem = $this->getFilesystem();

        if (!$filesystem->has($path)) {
            return $filesystem->put($path, '');
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_open($uri, $mode, $options, &$opened_path)
    {
        $this->uri = $uri;
        $path = $this->getTarget();

        $this->handle = $this->getStream($path, $mode);

        if ($this->handle && $options & STREAM_USE_PATH) {
            $opened_path = $path;
        }

        return (bool) $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_read($count)
    {
        if ($this->isWriteOnly) {
            return '';
        }

        return fread($this->handle, $count);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        if ($option === STREAM_OPTION_BLOCKING) {
            return stream_set_blocking($this->handle, $arg1);
        }

        // STREAM_OPTION_READ_TIMEOUT:
        // Not supported yet. There might be a way to use this to pass a timeout
        // to the underlying adapter.

        // STREAM_OPTION_WRITE_BUFFER:
        // Not supported. In the future, this could be supported.

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_stat()
    {
        return fstat($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_tell()
    {
        return ftell($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_truncate($new_size)
    {
        if ($this->isReadOnly) {
            return false;
        }
        $this->needsFlush = true;

        if ($this->isCow) {
            $this->isCow = false;
            $this->handle = $this->cloneStream($this->handle);
        }

        return ftruncate($this->handle, $new_size);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_write($data)
    {
        if ($this->isReadOnly) {
            return 0;
        }
        $this->needsFlush = true;

        if ($this->isCow) {
            $this->isCow = false;
            $this->handle = $this->cloneStream($this->handle);
        }

        // Enforce append semantics.
        if ($this->isAppendOnly) {
            fseek($this->handle, 0, SEEK_END);
        }

        return fwrite($this->handle, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function unlink($uri)
    {
        $this->uri = $uri;

        return $this->doUnlink($this->getTarget());
    }

    /**
     * Performs the actual deletion of a file.
     *
     * @param string $path An internal path.
     *
     * @return bool True on success, false on failure.
     */
    protected function doUnlink($path)
    {
        try {
            return $this->getFilesystem()->delete($path);
        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('unlink(%s): No such file or directory', $path), E_USER_WARNING);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function url_stat($uri, $flags)
    {
        $this->uri = $uri;

        try {
            $metadata = $this->getFilesystem()->getMetadata($this->getTarget());
        } catch (FileNotFoundException $e) {
            return false;
        }

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

    /**
     * Returns a stream for a given path and mode.
     *
     * @param string $path The path to open.
     * @param string $mode The mode to open the stream in.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getStream($path, $mode)
    {
        switch ($mode[0]) {
            case 'r':
                return $this->getReadStream($path, $mode);

            case 'w':
                $this->needsFlush = true;
                $this->isWriteOnly = strpos($mode, '+') === false;
                return fopen('php://temp', 'w+b');

            case 'a':
                return $this->getAppendStream($path, $mode);

            case 'x':
                return $this->getXStream($path, $mode);

            case 'c':
                return $this->getWritableStream($path, $mode);
        }

        return false;
    }

    /**
     * Returns a read-only stream for a given path and mode.
     *
     * @param string $path The path to open.
     * @param string $mode The mode to open the stream in.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getReadStream($path, $mode)
    {
        try {
            $handle = $this->getFilesystem()->readStream($path);
        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('fopen(%s): failed to open stream: No such file or directory', $this->uri), E_USER_WARNING);
            return false;
        }

        if (strpos($mode, '+') === false) {
            $this->isReadOnly = true;
            return $handle;
        }

        $this->isCow = !$this->handleIsWritable($handle);
        return $handle;
    }

    /**
     * Returns a writable stream for a given path and mode.
     *
     * @param string $path The path to open.
     * @param string $mode The mode to open the stream in.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getWritableStream($path, $mode)
    {
        try {
            $handle = $this->getFilesystem()->readStream($path);
            $this->isCow = !$this->handleIsWritable($handle);
        } catch (FileNotFoundException $e) {
            $handle = fopen('php://temp', 'w+b');
            $this->needsFlush = true;
        }

        $this->isWriteOnly = strpos($mode, '+') === false;

        return $handle;
    }

    /**
     * Returns an appendable stream for a given path and mode.
     *
     * @param string $path The path to open.
     * @param string $mode The mode to open the stream in.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getAppendStream($path, $mode)
    {
        $this->isAppendOnly = true;
        if ($handle = $this->getWritableStream($path, $mode)) {
            fseek($handle, 0, SEEK_END);
        }

        return $handle;
    }

    /**
     * Returns a writable stream for a given path and mode.
     *
     * Triggers a warning if the file exists.
     *
     * @param string $path The path to open.
     * @param string $mode The mode to open the stream in.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getXStream($path, $mode)
    {
        if ($this->getFilesystem()->has($path)) {
            trigger_error(sprintf('fopen(%s): failed to open stream: File exists', $this->uri), E_USER_WARNING);

            return false;
        }

        $this->needsFlush = true;
        $this->isWriteOnly = strpos($mode, '+') === false;

        return fopen('php://temp', 'w+b');
    }

    /**
     * Clones a stream.
     *
     * @param resource $handle The file handle to clone.
     *
     * @return resource The cloned file handle.
     */
    protected function cloneStream($handle)
    {
        $out = fopen('php://temp', 'w+b');
        $pos = ftell($handle);

        fseek($handle, 0);
        stream_copy_to_stream($handle, $out);
        fclose($handle);

        fseek($out, $pos);

        return $out;
    }

    /**
     * Determines if a file handle is writable.
     *
     * Most adapters return the read stream as a tempfile or a php temp stream.
     * For performance, avoid copying the temp stream if it is writable.
     *
     * @param resource|bool $handle A file handle.
     *
     * @return bool True if writable, false if not.
     */
    protected function handleIsWritable($handle)
    {
        if (!$handle) {
            return false; // @codeCoverageIgnore
        }

        $mode = stream_get_meta_data($handle)['mode'];

        if ($mode[0] === 'r') {
            return strpos($mode, '+') === 1;
        }

        return true;
    }

    /**
     * Determines whether errors should be reported.
     *
     * @param int $options Options passed to stream functions.
     *
     * @return bool True if errors should be reported.
     */
    protected function reportErrors($options)
    {
        return ($options & STREAM_REPORT_ERRORS) || defined('HHVM_VERSION');
    }

    /**
     * Returns the protocol from the internal URI.
     *
     * @return string The protocol.
     */
    protected function getProtocol()
    {
        return substr($this->uri, 0, strpos($this->uri, '://'));
    }

    /**
     * Returns the local writable target of the resource within the stream.
     *
     * @param string|null $uri The URI.
     *
     * @return string The path appropriate for use with Flysystem.
     */
    protected function getTarget($uri = null)
    {
        if (!isset($uri)) {
            $uri = $this->uri;
        }

        return substr($uri, strpos($uri, '://') + 3);
    }

    /**
     * Returns the filesystem.
     *
     * @return \League\Flysystem\FilesystemInterface The filesystem object.
     */
    protected function getFilesystem()
    {
        if (isset($this->filesystem)) {
            return $this->filesystem;
        }

        $this->filesystem = static::$filesystems[$this->getProtocol()];

        return $this->filesystem;
    }
}

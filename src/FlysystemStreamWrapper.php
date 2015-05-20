<?php

namespace Twistor;

use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\RootViolationException;
use Twistor\Flysystem\Plugin\ForcedRename;
use Twistor\Flysystem\Plugin\Mkdir;
use Twistor\Flysystem\Plugin\Rmdir;
use Twistor\Flysystem\Plugin\Stat;
use Twistor\Flysystem\Plugin\Touch;

/**
 * An adapter for Flysystem to a PHP stream wrapper.
 */
class FlysystemStreamWrapper
{
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

        static::registerPlugins($filesystem);
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

    protected static function registerPlugins(FilesystemInterface $filesystem)
    {
        $filesystem->addPlugin(new ForcedRename());
        $filesystem->addPlugin(new Mkdir());
        $filesystem->addPlugin(new Rmdir());
        $filesystem->addPlugin(new Stat());
        $filesystem->addPlugin(new Touch());
    }

    /**
     * Closes the directory handle.
     *
     * @return bool True on success, false on failure.
     */
    public function dir_closedir()
    {
        unset($this->listing);

        return true;
    }

    /**
     * Opens a directory handle.
     *
     * @param string $uri     The URL that was passed to opendir().
     * @param int    $options Whether or not to enforce safe_mode (0x04).
     *
     * @return bool True on success, false on failure.
     */
    public function dir_opendir($uri, $options)
    {
        $this->uri = $uri;
        $this->listing = $this->getFilesystem()->listContents($this->getTarget());

        return true;
    }

    /**
     * Reads an entry from directory handle.
     *
     * @return string|bool The next filename, or false if there is no next file.
     */
    public function dir_readdir()
    {
        $current = current($this->listing);
        next($this->listing);

        return $current ? $current['path'] : false;
    }

    /**
     * Rewinds the directory handle.
     *
     * @return bool True on success, false on failure.
     */
    public function dir_rewinddir()
    {
        reset($this->listing);

        return true;
    }

    /**
     * Creates a directory.
     *
     * @param string $uri
     * @param int    $mode
     * @param int    $options
     *
     * @return bool True on success, false on failure.
     */
    public function mkdir($uri, $mode, $options)
    {
        $this->uri = $uri;

        try {
            return $this->getFilesystem()->mkdir($this->getTarget(), $mode, $options);
        } catch (FileNotFoundException $e) {
            if ($this->reportErrors($options)) {
                trigger_error(sprintf('mkdir(%s): No such file or directory', $uri), E_USER_WARNING);
            }
        }

        return false;
    }

    /**
     * Renames a file or directory.
     *
     * @param string $uri_from
     * @param string $uri_to
     *
     * @return bool True on success, false on failure.
     */
    public function rename($uri_from, $uri_to)
    {
        $this->uri = $uri_from;

        try {
            return $this->getFilesystem()->forcedRename($this->getTarget($uri_from), $this->getTarget($uri_to));

        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('rename(%s,%s): No such file or directory', $uri_from, $uri_to), E_USER_WARNING);
        }

        return false;
    }

    /**
     * Removes a directory.
     *
     * @param string $uri
     * @param int    $options
     *
     * @return bool True on success, false on failure.
     */
    public function rmdir($uri, $options)
    {
        $this->uri = $uri;

        try {
            return $this->getFilesystem()->rmdir($this->getTarget(), $options);

        } catch (RootViolationException $e) {
            if ($this->reportErrors($options)) {
                trigger_error(sprintf('rmdir(%s): Cannot remove the root directory', $this->uri), E_USER_WARNING);
            }

        } catch (FileExistsException $e) {
            if ($this->reportErrors($options)) {
                trigger_error(sprintf('rmdir(%s): Directory not empty', $this->uri), E_USER_WARNING);
            }
        }

        return false;
    }

    /**
     * Retrieves the underlaying resource.
     *
     * @param int $cast_as
     *
     * @return resource|bool The stream resource used by the wrapper, or false.
     */
    public function stream_cast($cast_as)
    {
        return $this->handle;
    }

    /**
     * Closes the resource.
     */
    public function stream_close()
    {
        fclose($this->handle);
    }

    /**
     * Tests for end-of-file on a file pointer.
     *
     * @return bool True if the file is at the end, false if not.
     */
    public function stream_eof()
    {
        return feof($this->handle);
    }

    /**
     * Flushes the output.
     *
     * @return bool True on success, false on failure.
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
     * Advisory file locking.
     *
     * @param int $operation
     *
     * @return bool True on success, false on failure.
     */
    public function stream_lock($operation)
    {
        return flock($this->handle, $operation);
    }

    /**
     * Changes stream options.
     *
     * @param string $uri
     * @param int    $options
     * @param mixed  $value
     *
     * @return bool True on success, false on failure.
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
                return $this->getFilesystem()->touch($this->getTarget());

            default:
                return false;
        }
    }

    /**
     * Opens file or URL.
     *
     * @param string $uri
     * @param string $mode
     * @param int    $options
     * @param string &$opened_path
     *
     * @todo STREAM_REPORT_ERRORS
     *
     * @return bool True on success, false on failure.
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
     * Reads from stream.
     *
     * @param int $count
     *
     * @return string The bytes read.
     */
    public function stream_read($count)
    {
        if ($this->isWriteOnly) {
            return '';
        }

        return fread($this->handle, $count);
    }

    /**
     * Seeks to specific location in a stream.
     *
     * @param int $offset
     * @param int $whence
     *
     * @return bool True on success, false on failure.
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    /**
     * Changes stream options.
     *
     * @param int $option
     * @param int $arg1
     * @param int $arg2
     *
     * @return bool True on success, false on failure.
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
     * Retrieves information about a file resource.
     *
     * @return array A similar array to stat().
     *
     * @see stat()
     */
    public function stream_stat()
    {
        return fstat($this->handle);
    }

    /**
     * Retrieves the current position of a stream.
     *
     * @return int The current position of the stream.
     */
    public function stream_tell()
    {
        return ftell($this->handle);
    }

    /**
     * Truncates the stream.
     *
     * @param int $new_size
     *
     * @return bool True on success, false on failure.
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
     * Writes to the stream.
     *
     * @param string $data
     *
     * @return int The number of bytes that were successfully stored.
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
     * Deletes a file.
     *
     * @param string $uri
     *
     * @return bool True on success, false on failure.
     */
    public function unlink($uri)
    {
        $this->uri = $uri;

        try {
            return $this->getFilesystem()->delete($this->getTarget());
        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('unlink(%s): No such file or directory', $uri), E_USER_WARNING);
        }

        return false;
    }

    /**
     * Retrieves information about a file.
     *
     * @param string $uri
     * @param int    $flags
     *
     * @return array Output similar to stat().
     *
     * @see stat()
     */
    public function url_stat($uri, $flags)
    {
        $this->uri = $uri;

        try {
            return $this->getFilesystem()->stat($this->getTarget(), $flags);
        } catch (FileNotFoundException $e) {
            // File doesn't exist.
        }

        return false;
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

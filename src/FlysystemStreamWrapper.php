<?php

namespace Twistor;

use League\Flysystem\AdapterInterface;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\Util;
use Twistor\Flysystem\Exception\TriggerErrorException;
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
     * A flag to tell FlysystemStreamWrapper::url_stat() to ignore the size.
     *
     * @var int
     */
    const STREAM_URL_IGNORE_SIZE = 8;

    /**
     * The registered filesystems.
     *
     * @var \League\Flysystem\FilesystemInterface[]
     */
    protected static $filesystems = [];

    /**
     * Optional configuration.
     *
     * @var array
     */
    protected static $config = [];

    /**
     * The default configuration.
     *
     * @var array
     */
    protected static $defaultConfiguration = [
        'permissions' => [
            'dir' => [
                'private' => 0700,
                'public' => 0755,
            ],
            'file' => [
                'private' => 0600,
                'public' => 0644,
            ],
        ],
        'metadata' => ['timestamp', 'size', 'visibility'],
        'public_mask' => 0044,
    ];

    /**
     * The number of bytes that have been written since the last flush.
     *
     * @var int
     */
    protected $bytesWritten = 0;

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
     * Whether the handle is in append mode.
     *
     * @var bool
     */
    protected $isAppendMode = false;

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
     * Whether this handle has been verified writable.
     *
     * @var bool
     */
    protected $needsCowCheck = false;

    /**
     * Whether the handle should be flushed.
     *
     * @var bool
     */
    protected $needsFlush = false;

    /**
     * The handle used for calls to stream_lock.
     *
     * @var resource
     */
    protected $lockHandle;

    /**
     * If stream_set_write_buffer() is called, the arguments.
     *
     * @var int
     */
    protected $streamWriteBuffer;

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
     * @param string              $protocol      The protocol.
     * @param FilesystemInterface $filesystem    The filesystem.
     * @param array|null          $configuration Optional configuration.
     * @param int                 $flags         Should be set to STREAM_IS_URL if protocol is a URL protocol. Default is 0, local stream.
     *
     * @return bool True if the protocol was registered, false if not.
     */
    public static function register($protocol, FilesystemInterface $filesystem, array $configuration = null, $flags = 0)
    {
        if (static::streamWrapperExists($protocol)) {
            return false;
        }

        static::$config[$protocol] = $configuration ?: static::$defaultConfiguration;
        static::registerPlugins($protocol, $filesystem);
        static::$filesystems[$protocol] = $filesystem;

        return stream_wrapper_register($protocol, __CLASS__, $flags);
    }

    /**
     * Unregisters a stream wrapper.
     *
     * @param string $protocol The protocol.
     *
     * @return bool True if the protocol was unregistered, false if not.
     */
    public static function unregister($protocol)
    {
        if ( ! static::streamWrapperExists($protocol)) {
            return false;
        }

        unset(static::$filesystems[$protocol]);

        return stream_wrapper_unregister($protocol);
    }

    /**
     * Unregisters all controlled stream wrappers.
     */
    public static function unregisterAll()
    {
        foreach (static::getRegisteredProtocols() as $protocol) {
            static::unregister($protocol);
        }
    }

    /**
     * @return array The list of registered protocols.
     */
    public static function getRegisteredProtocols()
    {
        return array_keys(static::$filesystems);
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
     * Registers plugins on the filesystem.
     * @param string $protocol
     * @param FilesystemInterface $filesystem
     */
    protected static function registerPlugins($protocol, FilesystemInterface $filesystem)
    {
        $filesystem->addPlugin(new ForcedRename());
        $filesystem->addPlugin(new Mkdir());
        $filesystem->addPlugin(new Rmdir());

        $stat = new Stat(
            static::$config[$protocol]['permissions'],
            static::$config[$protocol]['metadata']
        );

        $filesystem->addPlugin($stat);
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

        $path = Util::normalizePath($this->getTarget());

        $this->listing = $this->invoke($this->getFilesystem(), 'listContents', [$path], 'opendir');

        if ($this->listing === false) {
            return false;
        }

        if ( ! $dirlen = strlen($path)) {
            return true;
        }

        // Remove the separator /.
        $dirlen++;

        // Remove directory prefix.
        foreach ($this->listing as $delta => $item) {
            $this->listing[$delta]['path'] = substr($item['path'], $dirlen);
        }

        reset($this->listing);

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

        return $this->invoke($this->getFilesystem(), 'mkdir', [$this->getTarget(), $mode, $options]);
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
        $args = [$this->getTarget($uri_from), $this->getTarget($uri_to)];

        return $this->invoke($this->getFilesystem(), 'forcedRename', $args, 'rename');
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

        return $this->invoke($this->getFilesystem(), 'rmdir', [$this->getTarget(), $options]);
    }

    /**
     * Retrieves the underlying resource.
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
        // PHP 7 doesn't call flush automatically anymore for truncate() or when
        // writing an empty file. We need to ensure that the handle gets pushed
        // as needed in that case. This will be a no-op for php 5.
        $this->stream_flush();

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
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
        if ( ! $this->needsFlush) {
            return true;
        }

        $this->needsFlush = false;
        $this->bytesWritten = 0;

        // Calling putStream() will rewind our handle. flush() shouldn't change
        // the position of the file.
        $pos = ftell($this->handle);

        $args = [$this->getTarget(), $this->handle];
        $success = $this->invoke($this->getFilesystem(), 'putStream', $args, 'fflush');

        if (is_resource($this->handle)) {
            fseek($this->handle, $pos);
        }

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
        $operation = (int) $operation;

        if (($operation & \LOCK_UN) === \LOCK_UN) {
            return $this->releaseLock($operation);
        }

        // If the caller calls flock() twice, there's no reason to re-create the
        // lock handle.
        if (is_resource($this->lockHandle)) {
            return flock($this->lockHandle, $operation);
        }

        $this->lockHandle = $this->openLockHandle();

        return is_resource($this->lockHandle) && flock($this->lockHandle, $operation);
    }

    /**
     * Changes stream options.
     *
     * @param string $uri
     * @param int    $option
     * @param mixed  $value
     *
     * @return bool True on success, false on failure.
     */
    public function stream_metadata($uri, $option, $value)
    {
        $this->uri = $uri;

        switch ($option) {
            case STREAM_META_ACCESS:
                $permissions = octdec(substr(decoct($value), -4));
                $is_public = $permissions & $this->getConfiguration('public_mask');
                $visibility =  $is_public ? AdapterInterface::VISIBILITY_PUBLIC : AdapterInterface::VISIBILITY_PRIVATE;

                try {
                    return $this->getFilesystem()->setVisibility($this->getTarget(), $visibility);
                } catch (\LogicException $e) {
                    // The adapter doesn't support visibility.
                } catch (\Exception $e) {
                    $this->triggerError('chmod', $e);

                    return false;
                }

                return true;

            case STREAM_META_TOUCH:
                return $this->invoke($this->getFilesystem(), 'touch', [$this->getTarget()]);

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
     * @return bool True on success, false on failure.
     */
    public function stream_open($uri, $mode, $options, &$opened_path)
    {
        $this->uri = $uri;
        $path = $this->getTarget();

        $this->isReadOnly = StreamUtil::modeIsReadOnly($mode);
        $this->isWriteOnly = StreamUtil::modeIsWriteOnly($mode);
        $this->isAppendMode = StreamUtil::modeIsAppendable($mode);

        $this->handle = $this->invoke($this, 'getStream', [$path, $mode], 'fopen');

        if ($this->handle && $options & STREAM_USE_PATH) {
            $opened_path = $path;
        }

        return is_resource($this->handle);
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
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                // This works for the local adapter. It doesn't do anything for
                // memory streams.
                return stream_set_blocking($this->handle, $arg1);

            case STREAM_OPTION_READ_TIMEOUT:
                return  stream_set_timeout($this->handle, $arg1, $arg2);

            case STREAM_OPTION_READ_BUFFER:
                if ($arg1 === STREAM_BUFFER_NONE) {
                    return stream_set_read_buffer($this->handle, 0) === 0;
                }

                return stream_set_read_buffer($this->handle, $arg2) === 0;

            case STREAM_OPTION_WRITE_BUFFER:
                $this->streamWriteBuffer = $arg1 === STREAM_BUFFER_NONE ? 0 : $arg2;

                return true;
        }

        return false;
    }

    /**
     * Retrieves information about a file resource.
     *
     * @return array A similar array to fstat().
     *
     * @see fstat()
     */
    public function stream_stat()
    {
        // Get metadata from original file.
        $stat = $this->url_stat($this->uri, static::STREAM_URL_IGNORE_SIZE | STREAM_URL_STAT_QUIET) ?: [];

        // Newly created file.
        if (empty($stat['mode'])) {
            $stat['mode'] = 0100000 + $this->getConfiguration('permissions')['file']['public'];
            $stat[2] = $stat['mode'];
        }

        // Use the size of our handle, since it could have been written to or
        // truncated.
        $stat['size'] = $stat[7] = StreamUtil::getSize($this->handle);

        return $stat;
    }

    /**
     * Retrieves the current position of a stream.
     *
     * @return int The current position of the stream.
     */
    public function stream_tell()
    {
        if ($this->isAppendMode) {
            return 0;
        }

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
        $this->ensureWritableHandle();

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
        $this->ensureWritableHandle();

        // Enforce append semantics.
        if ($this->isAppendMode) {
            StreamUtil::trySeek($this->handle, 0, SEEK_END);
        }

        $written = fwrite($this->handle, $data);
        $this->bytesWritten += $written;

        if (isset($this->streamWriteBuffer) && $this->bytesWritten >= $this->streamWriteBuffer) {
            $this->stream_flush();
        }

        return $written;
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

        return $this->invoke($this->getFilesystem(), 'delete', [$this->getTarget()], 'unlink');
    }

    /**
     * Retrieves information about a file.
     *
     * @param string $uri
     * @param int    $flags
     *
     * @return array|false Output similar to stat().
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
            if ( ! ($flags & STREAM_URL_STAT_QUIET)) {
                $this->triggerError('stat', $e);
            }
        } catch (\Exception $e) {
            $this->triggerError('stat', $e);
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
     *
     * @throws \League\Flysystem\FileNotFoundException
     */
    protected function getStream($path, $mode)
    {
        switch ($mode[0]) {
            case 'r':
                $this->needsCowCheck = true;

                return $this->getFilesystem()->readStream($path);

            case 'w':
                $this->needsFlush = true;

                return fopen('php://temp', 'w+b');

            case 'a':
                return $this->getAppendStream($path);

            case 'x':
                return $this->getXStream($path);

            case 'c':
                return $this->getWritableStream($path);
        }

        return false;
    }

    /**
     * Returns a writable stream for a given path and mode.
     *
     * @param string $path The path to open.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getWritableStream($path)
    {
        try {
            $handle = $this->getFilesystem()->readStream($path);
            $this->needsCowCheck = true;
        } catch (FileNotFoundException $e) {
            $handle = fopen('php://temp', 'w+b');
            $this->needsFlush = true;
        }

        return $handle;
    }

    /**
     * Returns an appendable stream for a given path and mode.
     *
     * @param string $path The path to open.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getAppendStream($path)
    {
        if ($handle = $this->getWritableStream($path)) {
            StreamUtil::trySeek($handle, 0, SEEK_END);
        }

        return $handle;
    }

    /**
     * Returns a writable stream for a given path and mode.
     *
     * Triggers a warning if the file exists.
     *
     * @param string $path The path to open.
     *
     * @return resource|bool The file handle, or false.
     */
    protected function getXStream($path)
    {
        if ($this->getFilesystem()->has($path)) {
            trigger_error('fopen(): failed to open stream: File exists', E_USER_WARNING);

            return false;
        }

        $this->needsFlush = true;

        return fopen('php://temp', 'w+b');
    }

    /**
     * Guarantees that the handle is writable.
     */
    protected function ensureWritableHandle()
    {
        if ( ! $this->needsCowCheck) {
            return;
        }

        $this->needsCowCheck = false;

        if (StreamUtil::isWritable($this->handle)) {
            return;
        }

        $this->handle = StreamUtil::copy($this->handle);
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
        if ( ! isset($uri)) {
            $uri = $this->uri;
        }

        $target = substr($uri, strpos($uri, '://') + 3);

        return $target === false ? '' : $target;
    }

    /**
     * Returns the configuration.
     *
     * @param string|null $key The optional configuration key.
     *
     * @return array The requested configuration.
     */
    protected function getConfiguration($key = null)
    {
        return $key ? static::$config[$this->getProtocol()][$key] : static::$config[$this->getProtocol()];
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

    /**
     * Calls a method on an object, catching any exceptions.
     *
     * @param object      $object    The object to call the method on.
     * @param string      $method    The method name.
     * @param array       $args      The arguments to the method.
     * @param string|null $errorname The name of the calling function.
     *
     * @return mixed|false The return value of the call, or false on failure.
     */
    protected function invoke($object, $method, array $args, $errorname = null)
    {
        try {
            return call_user_func_array([$object, $method], $args);
        } catch (\Exception $e) {
            $errorname = $errorname ?: $method;
            $this->triggerError($errorname, $e);
        }

        return false;
    }

    /**
     * Calls trigger_error(), printing the appropriate message.
     *
     * @param string     $function
     * @param \Exception $e
     */
    protected function triggerError($function, \Exception $e)
    {
        if ($e instanceof TriggerErrorException) {
            trigger_error($e->formatMessage($function), E_USER_WARNING);

            return;
        }

        switch (get_class($e)) {
            case 'League\Flysystem\FileNotFoundException':
                trigger_error(sprintf('%s(): No such file or directory', $function), E_USER_WARNING);

                return;

            case 'League\Flysystem\RootViolationException':
                trigger_error(sprintf('%s(): Cannot remove the root directory', $function), E_USER_WARNING);

                return;
        }

        // Don't allow any exceptions to leak.
        trigger_error($e->getMessage(), E_USER_WARNING);
    }

    /**
     * Creates an advisory lock handle.
     *
     * @return resource|false
     */
    protected function openLockHandle()
    {
        // PHP allows periods, '.', to be scheme names. Normalize the scheme
        // name to something that won't cause problems. Also, avoid problems
        // with case-insensitive filesystems. We use bin2hex() rather than a
        // hashing function since most scheme names are small, and bin2hex()
        // only doubles the string length.
        $sub_dir = bin2hex($this->getProtocol());

        // Since we're flattening out whole filesystems, at least create a
        // sub-directory for each scheme to attempt to reduce the number of
        // files per directory.
        $temp_dir = sys_get_temp_dir() . '/flysystem-stream-wrapper/' . $sub_dir;

        // Race free directory creation. If @mkdir() fails, fopen() will fail
        // later, so there's no reason to test again.
        ! is_dir($temp_dir) && @mkdir($temp_dir, 0777, true);

        // Normalize paths so that locks are consistent.
        // We are using sha1() to avoid the file name limits, and case
        // insensitivity on Windows. This is not security sensitive.
        $lock_key = sha1(Util::normalizePath($this->getTarget()));

        // Relay the lock to a real filesystem lock.
        return fopen($temp_dir . '/' . $lock_key, 'c');
    }

    /**
     * Releases the advisory lock.
     *
     * @param int $operation
     *
     * @return bool
     *
     * @see FlysystemStreamWrapper::stream_lock()
     */
    protected function releaseLock($operation)
    {
        $exists = is_resource($this->lockHandle);

        $success = $exists && flock($this->lockHandle, $operation);

        $exists && fclose($this->lockHandle);
        $this->lockHandle = null;

        return $success;
    }
}

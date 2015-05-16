<?php

namespace Twistor;

use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\RootViolationException;

/**
 * An adapter for Flysystem to a PHP stream wrapper.
 */
class FlysystemStreamWrapper {

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
   * @var resource
   */
  protected $handle;

  /**
   * A directory listing.
   *
   * @var array
   */
  protected $listing;

  /**
   * Instance URI (stream).
   *
   * A stream is referenced as "protocol://target".
   *
   * @var string
   */
  protected $uri;

  /**
   * Registers the stream wrapper if not already registered.
   *
   * @param string $protocol
   *   The protocol.
   * @param \League\Flysystem\FilesystemInterface $filesystem
   *   The filesystem.
   *
   * @return bool
   *   True if the protocal was registered, false if not.
   */
  public static function register($protocol, FilesystemInterface $filesystem) {
    if (in_array($protocol, stream_get_wrappers(), TRUE)) {
      return FALSE;
    }

    static::$filesystems[$protocol] = $filesystem;
    return stream_wrapper_register($protocol, __CLASS__);
  }

  /**
   * Unegisters a stream wrapper.
   *
   * @param string $protocol
   *   The protocol.
   *
   * @return bool
   *   True if the protocal was unregistered, false if not.
   */
  public static function unregister($protocol) {
    if (!in_array($protocol, stream_get_wrappers(), TRUE)) {
      return FALSE;
    }

    return stream_wrapper_unregister($protocol);
  }

  /**
   * Returns the protocol from the internal URI.
   *
   * @return string
   *   The protocol.
   */
  protected function getProtocol() {
    return substr($this->uri, 0, strpos($this->uri, '://'));
  }

  /**
   * Returns the local writable target of the resource within the stream.
   *
   * @param string $uri
   *   (optional) The URI.
   *
   * @return string
   *   The path appropriate for use with Flysystem.
   */
  protected function getTarget($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    return substr($uri, strpos($uri, '://') + 3);
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir() {
    unset($this->listing);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($uri, $options) {
    $this->uri = $uri;
    $this->listing = $this->getFilesystem()->listContents($this->getTarget());
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir() {
    $current = current($this->listing);
    next($this->listing);
    return $current ? $current['path'] : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir() {
    reset($this->listing);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($uri, $mode, $options) {
    $this->uri = $uri;
    // @todo mode handling.
    // $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);
    return $this->getFilesystem()->createDir($this->getTarget());
  }

  /**
   * {@inheritdoc}
   */
  public function rename($uri_from, $uri_to) {
    // Ignore useless renames.
    if ($uri_from === $uri_to) {
      return TRUE;
    }

    $this->uri = $uri_from;

    $filesystem = $this->getFilesystem();
    $path_from = $this->getTarget($uri_from);
    $path_to = $this->getTarget($uri_to);

    try {
      return $filesystem->rename($path_from, $path_to);
    }
    catch (FileNotFoundException $e) {
      trigger_error(sprintf('%s(%s,%s): No such file or directory', __FUNCTION__, $path_from, $path_to), E_USER_WARNING);
    }

    // PHP's rename() will overwrite an existing file. Emulate that.
    catch (FileExistsException $e) {
      if ($this->doUnlink($path_to)) {
        return $filesystem->rename($path_from, $path_to);
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $options) {
    $this->uri = $uri;
    try {
      return $this->getFilesystem()->deleteDir($this->getTarget());
    }
    catch (RootViolationException $e) {
      trigger_error(sprintf('%s(%s): Cannot remove the root directory', __FUNCTION__, $this->getTarget()), E_USER_WARNING);
    }
    catch (\UnexpectedValueException $e) {
      // Thrown by a directory interator when the perms fail.
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    return $this->handle ?: FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close() {
    fclose($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof() {
    return feof($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush() {
    // Calling putStream() will rewind our handle. flush() shouldn't change the
    // position of the file.
    $pos = ftell($this->handle);

    $success = $this->getFilesystem()->putStream($this->getTarget(), $this->handle);

    fseek($this->handle, $pos);

    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation) {
    return flock($this->handle, $operation);
  }
  /**
   * {@inheritdoc}
   */
  public function stream_metadata($uri, $option, $value) {
    $this->uri = $uri;
    // $path = $this->getTarget();

    switch ($option) {
      case STREAM_META_ACCESS:
        return TRUE;

      case STREAM_META_TOUCH:
        // Emulate touch().
        $filesystem = $this->getFilesystem();
        $path = $this->getTarget();

        if (!$filesystem->has($path)) {
          $filesystem->put($path, '');
        }

        return TRUE;
    }
    // @todo
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->uri = $uri;
    $path = $this->getTarget();

    $this->handle = fopen('php://temp', 'r+');

    try {
      $reader = $this->getFilesystem()->readStream($path);

      if ($reader) {
        // Some adapters are read only streams, so we can't depend on writing to
        // them.
        stream_copy_to_stream($reader, $this->handle);
        fclose($reader);
        rewind($this->handle);
      }
    }
    catch (FileNotFoundException $e) {}

    if ((bool) $this->handle && $options & STREAM_USE_PATH) {
      $opened_path = $path;
    }

    return (bool) $this->handle;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count) {
    return fread($this->handle, $count);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    return !fseek($this->handle, $offset, $whence);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2) {
    switch ($option) {
      case STREAM_OPTION_BLOCKING:
        return stream_set_blocking($this->handle, $arg1);

      case STREAM_OPTION_READ_TIMEOUT:
        return stream_set_timeout($this->handle, $arg1, $arg2);

      case STREAM_OPTION_WRITE_BUFFER:
        return stream_set_write_buffer($this->handle, $arg1, $arg2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat() {
    return fstat($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell() {
    return ftell($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    return ftruncate($this->handle, $new_size);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data) {
    return fwrite($this->handle, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri) {
    $this->uri = $uri;
    return $this->doUnlink($this->getTarget());
  }

  /**
   * Performs the actual deletion of a file.
   *
   * @param string $path
   *   An internal path.
   *
   * @return bool
   *   True on success, false on failure.
   */
  protected function doUnlink($path) {
    try {
      return $this->getFilesystem()->delete($path);
    }
    catch (FileNotFoundException $e) {
      trigger_error(sprintf('%s(%s): No such file or directory', 'unlink', $path), E_USER_WARNING);
    }
    catch (\UnexpectedValueException $e) {
      // oops.
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($uri, $flags) {
    $this->uri = $uri;

    $ret = [
      'dev' => 0,
      'ino' => 0,
      'mode' => 0,
      'nlink' => 0,
      'uid' => 0,
      'gid' => 0,
      'rdev' => 0,
      'size' => 0,
      'atime' => time(),
      'mtime' => 0,
      'ctime' => 0,
      'blksize' => -1,
      'blocks' => -1,
    ];

    try {
      $metadata = $this->getFilesystem()->getMetadata($this->getTarget());
    }
    catch (FileNotFoundException $e) {
      return FALSE;
    }

    // It's possible for getMetadata() to fail even if a file exists.
    // @todo Figure out the correct way to handle this.
    if ($metadata === FALSE) {
      return $ret;
    }

    if ($metadata['type'] === 'dir') {
      // Mode 0777.
      $ret['mode'] = 16895;
    }
    elseif ($metadata['type'] === 'file') {
      // Mode 0666.
      $ret['mode'] = 33204;
    }

    if (isset($metadata['size'])) {
      $ret['size'] = $metadata['size'];
    }
    if (isset($metadata['timestamp'])) {
      $ret['mtime'] = $metadata['timestamp'];
      $ret['ctime'] = $metadata['timestamp'];
    }

    return array_merge(array_values($ret), $ret);
  }

  /**
   * Returns the filesystem.
   *
   * @return \League\Flysystem\FilesystemInterface
   *   The filesystem object.
   */
  protected function getFilesystem() {
    if (isset($this->filesystem)) {
      return $this->filesystem;
    }

    $protocol = $this->getProtocol();

    if (isset(static::$filesystems[$protocol])) {
      $this->filesystem = static::$filesystems[$protocol];
    }
    else {
      $this->filesystem = new Filesystem(new NullAdapter());
    }

    return $this->filesystem;
  }

}

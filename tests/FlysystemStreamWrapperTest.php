<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

class FlysystemStreamWrapperTest extends \PHPUnit_Framework_TestCase {

  protected $testDir = __DIR__ . '/testdir';

  public function setUp() {
    $filesystem = new Filesystem(new Local(__DIR__));
    $filesystem->deleteDir('testdir');
    $filesystem->createDir('testdir');
  }

  public function tearDown() {
    $filesystem = new Filesystem(new Local(__DIR__));
    $filesystem->deleteDir('testdir');
  }

  protected function getFilesystem() {
    $filesystem = new Filesystem(new Local($this->testDir));
    FlysystemStreamWrapper::register('flysystem', $filesystem);
    return $filesystem;
  }

  public function testRegister() {
    $filesystem = new Filesystem(new NullAdapter());
    FlysystemStreamWrapper::register('test', $filesystem);

    $this->assertTrue(in_array('test', stream_get_wrappers(), TRUE));

    // Registering twice should be a noop.
    FlysystemStreamWrapper::register('test', $filesystem);
  }

  public function testMeta() {
    $filesystem = $this->getFilesystem();
    $this->assertSame(file_put_contents('flysystem://test_file.txt', 'contents'), 8);

    $this->assertTrue(file_exists('flysystem://test_file.txt'));

    $this->assertFalse(file_exists('flysystem://not_exist'));

    $this->assertTrue(mkdir('flysystem://test_dir'));
    $this->assertTrue(is_dir('flysystem://test_dir'));

    // Test touch.
    $this->assertTrue(touch('flysystem://touched'));

    // Chown isn't supported.
    $this->assertFalse(chown('flysystem://touched', 'asfasf'));

    // Chmod is faked.
    $this->assertTrue(chmod('flysystem://touched', 0777));
  }

  public function testWrite() {
    $filesystem = $this->getFilesystem();

    file_put_contents('flysystem://test_file.txt', 'contents');
    $this->assertSame(file_get_contents($this->testDir . '/test_file.txt'), 'contents');
    $this->assertSame(file_get_contents('flysystem://test_file.txt'), 'contents');

    // Test unlink.
    $this->assertTrue(unlink('flysystem://test_file.txt'));
  }

  public function testRename() {
    $filesystem = $this->getFilesystem();

    file_put_contents('flysystem://test_file.txt', 'contents');

    rename('flysystem://test_file.txt', 'flysystem://test_file.txt');
    $this->assertSame(file_get_contents('flysystem://test_file.txt'), 'contents');

    rename('flysystem://test_file.txt', 'flysystem://test_file2.txt');
    $this->assertSame(file_get_contents('flysystem://test_file2.txt'), 'contents');

    // Test overwriting existing files.
    file_put_contents('flysystem://test_file3.txt', '');
    rename('flysystem://test_file2.txt', 'flysystem://test_file3.txt');
  }

  /**
   * @expectedException PHPUnit_Framework_Error_Warning
   */
  public function testBadRename() {
    $filesystem = $this->getFilesystem();
    rename('flysystem://test_file1.txt', 'flysystem://test_file3.txt');
  }

  public function testRmdir() {
    $filesystem = $this->getFilesystem();
    mkdir($this->testDir . '/bad');

    chmod($this->testDir . '/bad', 0000);
    $this->assertFalse(rmdir('flysystem://bad'));

    chmod($this->testDir . '/bad', 0755);
    $this->assertTrue(rmdir('flysystem://bad'));
  }

  public function testTruncateTellAndSeek() {
    $filesystem = $this->getFilesystem();

    file_put_contents('flysystem://test_file.txt', 'contents');

    $handle = fopen('flysystem://test_file.txt', 'r+');

    fseek($handle, 1);

    $this->assertSame(ftell($handle), 1);

    ftruncate($handle, 0);
    fclose($handle);
    $this->assertSame(file_get_contents('flysystem://test_file.txt'), '');
  }

  /**
   * @expectedException PHPUnit_Framework_Error_Warning
   */
  public function testFailedRmdir() {
    $filesystem = $this->getFilesystem();
    rmdir('flysystem://');
  }

  /**
   * @expectedException PHPUnit_Framework_Error_Warning
   */
  public function testFailedUnlink() {
    $filesystem = $this->getFilesystem();
    unlink('flysystem://asdfasdfasf');
  }

  public function testLock() {
    $filesystem = $this->getFilesystem();
    $handle = fopen('flysystem://file', 'r+');
    $this->assertFalse(flock($handle, LOCK_SH));
  }

}

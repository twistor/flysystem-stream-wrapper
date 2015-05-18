<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

class FlysystemStreamWrapperTest extends \PHPUnit_Framework_TestCase
{
    protected $testDir;

    protected $filesystem;

    public function setUp()
    {
        $this->testDir = __DIR__ . '/testdir';

        $filesystem = new Filesystem(new Local(__DIR__));
        $filesystem->deleteDir('testdir');
        $filesystem->createDir('testdir');

        $this->filesystem = new Filesystem(new Local($this->testDir));
        FlysystemStreamWrapper::register('flysystem', $this->filesystem);
    }

    public function tearDown()
    {
        $filesystem = new Filesystem(new Local(__DIR__));
        $filesystem->deleteDir('testdir');
    }

    public function testRegister()
    {
        $filesystem = new Filesystem(new NullAdapter());
        $this->assertTrue(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(in_array('test', stream_get_wrappers(), true));

        // Registering twice should be a noop.
        $this->assertFalse(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(FlysystemStreamWrapper::unregister('test'));
        $this->assertFalse(FlysystemStreamWrapper::unregister('test'));
    }

    public function testMeta()
    {
        $this->putContent('test_file.txt', 'contents');

        $this->assertWrapperFileNotExists('not_exist');

        $this->assertTrue(mkdir('flysystem://test_dir'));
        $this->assertTrue(is_dir('flysystem://test_dir'));

        // Test touch.
        $this->assertTrue(touch('flysystem://touched'));
        $this->assertFileContent('touched', '');

        // Test touching an existing file.
        $this->assertTrue(touch('flysystem://test_file.txt'));
        $this->assertFileContent('test_file.txt', 'contents');

        // Chown isn't supported.
        $this->assertFalse(chown('flysystem://touched', 'asfasf'));

        // Chmod is faked.
        $this->assertTrue(chmod('flysystem://touched', 0777));
    }

    public function testUnlink()
    {
        $this->putContent('test_file.txt', 'contents');

        $this->assertTrue(unlink('flysystem://test_file.txt'));
        $this->assertWrapperFileNotExists('test_file.txt');
    }

    public function testRename()
    {
        $this->putContent('test_file.txt', 'contents');

        // Test rename to same file.
        rename('flysystem://test_file.txt', 'flysystem://test_file.txt');
        $this->assertFileContent('test_file.txt', 'contents');

        rename('flysystem://test_file.txt', 'flysystem://test_file2.txt');
        $this->assertFileContent('test_file2.txt', 'contents');
        $this->assertWrapperFileNotExists('test_file.txt');

        // // Test overwriting existing files.
        $this->putContent('test_file3.txt', 'oops');
        rename('flysystem://test_file2.txt', 'flysystem://test_file3.txt');
        $this->assertWrapperFileNotExists('test_file2.txt');
        $this->assertFileContent('test_file3.txt', 'contents');
    }

    public function testRmdir()
    {
        mkdir($this->testDir . '/bad');

        chmod($this->testDir . '/bad', 0000);
        $this->assertFalse(rmdir('flysystem://bad'));

        chmod($this->testDir . '/bad', 0755);
        $this->assertTrue(rmdir('flysystem://bad'));
    }

    public function testTruncateTellAndSeek()
    {
        $this->putContent('test_file.txt', 'contents');

        $handle = fopen('flysystem://test_file.txt', 'r+');

        fseek($handle, 1);

        $this->assertSame(1, ftell($handle));

        ftruncate($handle, 0);
        fclose($handle);
        $this->assertFileContent('test_file.txt', '');
    }

    public function testLock()
    {
        $handle = fopen('flysystem://file', 'w');

        // HHVM allows locks on memory handles?
        if (defined('HHVM_VERSION')) {
            $this->assertTrue(flock($handle, LOCK_SH));
        } else {
            $this->assertFalse(flock($handle, LOCK_SH));
        }
    }

    public function testSetOption()
    {
        $handle = fopen('flysystem://thing', 'w+');

        $this->assertTrue(stream_set_blocking($handle, 0));
        $this->assertFalse(stream_set_timeout($handle, 10));
        $this->assertSame(stream_set_write_buffer($handle, 100), -1);

        fclose($handle);
    }

    public function testDirectoryIteration()
    {
        mkdir('flysystem://one');
        mkdir('flysystem://two');
        mkdir('flysystem://three');

        $dir = opendir('flysystem://');
        $this->assertSame(readdir($dir), 'one');
        $this->assertSame(readdir($dir), 'two');
        $this->assertSame(readdir($dir), 'three');

        $this->assertFalse(readdir($dir));

        rewinddir($dir);
        $this->assertSame(readdir($dir), 'one');

        closedir($dir);
    }

    public function testSelect()
    {
        $handle = fopen('flysystem://thing', 'w+', true);

        $read = [$handle];
        $write = null;
        $except = null;
        $this->assertSame(stream_select($read, $write, $except, 10), 1);
        fclose($handle);
    }

    public function testAppendMode()
    {
        $this->putContent('test_file.txt', 'some file content');
        $handle = fopen('flysystem://test_file.txt', 'a+');
        $this->assertSame(17, ftell($handle));
        fclose($handle);

        $handle = fopen('flysystem://test_file.txt', 'c+');
        $this->assertSame(0, ftell($handle));
        $this->assertSame('some file content', fread($handle, 100));
        fclose($handle);

        // Test create file.
        $handle = fopen('flysystem://new_file.txt', 'a');
        $this->assertSame(5, fwrite($handle, '12345'));
        fclose($handle);
        $this->assertFileContent('new_file.txt', '12345');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testXMode()
    {
        $handle = fopen('flysystem://new_file.txt', 'x+');
        $this->assertSame(5, fwrite($handle, '12345'));
        fclose($handle);
        $this->assertFileContent('new_file.txt', '12345');

        // Throws warning.
        $handle = fopen('flysystem://new_file.txt', 'x+');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testInvalidMode()
    {
        $this->assertFalse(fopen('flysystem://test_file.txt', 'i'));
    }

    public function testWriteEmptyFile()
    {
        $this->putContent('file', '');
        $this->assertWrapperFileExists('file');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testFailedRmdir()
    {
        rmdir('flysystem://');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testFailedUnlink()
    {
        unlink('flysystem://asdfasdfasf');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testBadRename()
    {
        rename('flysystem://test_file1.txt', 'flysystem://test_file3.txt');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testReadMissing()
    {
        fopen('flysystem://doesnotexist', 'rbt');
    }

    protected function assertFileContent($path, $content)
    {
        $this->assertSame($content, file_get_contents("flysystem://$path"));
        $this->assertSame($content, file_get_contents($this->testDir . "/$path"));
    }

    protected function assertWrapperFileExists($path)
    {
        $this->assertTrue(file_exists("flysystem://$path"));
        $this->assertTrue(file_exists($this->testDir . "/$path"));
    }

    protected function assertWrapperFileNotExists($path)
    {
        $this->assertFalse(file_exists("flysystem://$path"));
        $this->assertFalse(file_exists($this->testDir . "/$path"));
    }

    protected function putContent($file, $content)
    {
        $len = file_put_contents("flysystem://$file", $content);
        $this->assertSame(strlen($content), $len);
        $this->assertFileContent($file, $content);
    }
}

<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

class StreamOperationTest extends \PHPUnit_Framework_TestCase
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
        FlysystemStreamWrapper::unregister('flysystem');
        $filesystem = new Filesystem(new Local(__DIR__));
        $filesystem->deleteDir('testdir');
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
    }

    public function testChmod()
    {
        $this->putContent('file.txt', 'contents');

        $this->assertTrue(chmod('flysystem://file.txt', 0777));
        $this->assertPerm('file.txt', 0744);
        $this->assertSame(0100744, fileperms('flysystem://file.txt'));

        $this->assertTrue(chmod('flysystem://file.txt', 0333));
        $this->assertPerm('file.txt', 0700);
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

        // Test overwriting existing files.
        $this->putContent('test_file3.txt', 'oops');
        rename('flysystem://test_file2.txt', 'flysystem://test_file3.txt');
        $this->assertWrapperFileNotExists('test_file2.txt');
        $this->assertFileContent('test_file3.txt', 'contents');

        // Test directories.
        $this->assertTrue(mkdir('flysystem://dir1'));
        $this->assertTrue(mkdir('flysystem://dir2'));
        $this->assertTrue(rename('flysystem://dir1', 'flysystem://dir2'));
        $this->assertFalse(is_dir('flysystem://dir1'));
        $this->assertTrue(is_dir('flysystem://dir2'));
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rename(flysystem://file,flysystem://dir): Is a directory
     */
    public function testRenameFileToDir()
    {
        // Test overwriting directory with file.
        mkdir('flysystem://dir');
        touch('flysystem://file');
        $this->assertFalse(@rename('flysystem://file', 'flysystem://dir'));
        rename('flysystem://file', 'flysystem://dir');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rename(flysystem://dir,flysystem://file): Not a directory
     */
    public function testRenameDirToFile()
    {
        // Test overwriting directory with file.
        mkdir('flysystem://dir');
        touch('flysystem://file');
        $this->assertFalse(@rename('flysystem://dir', 'flysystem://file'));
        rename('flysystem://dir', 'flysystem://file');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rename(flysystem://dir1,flysystem://dir2): Directory not empty
     */
    public function testRenameDirNotEmpty()
    {
        $this->assertTrue(mkdir('flysystem://dir1'));
        $this->assertTrue(mkdir('flysystem://dir2/boo', 0777, true));
        $this->assertFalse(@rename('flysystem://dir1', 'flysystem://dir2'));
        rename('flysystem://dir1', 'flysystem://dir2');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rename(flysystem://file,flysystem://dir/file): No such file or directory
     */
    public function testRenameNoSubDir()
    {
        $this->assertTrue(touch('flysystem://file'));
        $this->assertFalse(@rename('flysystem://file', 'flysystem://dir/file'));
        rename('flysystem://file', 'flysystem://dir/file');
    }

    public function testRmdirMkdir()
    {
        // Checks usage of STREAM_REPORT_ERRORS.
        $this->assertFalse(@mkdir('flysystem://one/two', 0777));

        $this->assertTrue(mkdir('flysystem://one/two', 0777, STREAM_MKDIR_RECURSIVE));
        $this->assertTrue(mkdir('flysystem://one/two/three', 0777));

        $this->assertFalse(@rmdir('flysystem://one'));
        $this->assertTrue(rmdir('flysystem://one/two/three'));
        $this->assertFalse(is_dir($this->testDir . '/one/two/three'));
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage mkdir(flysystem://one/two): No such file or directory
     */
    public function testMkdirFail()
    {
        mkdir('flysystem://one/two', 0777);
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rmdir(flysystem://one): Directory not empty
     */
    public function testRmdirFail()
    {
        $this->assertTrue(mkdir('flysystem://one/two', 0777, STREAM_MKDIR_RECURSIVE));
        rmdir('flysystem://one');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rmdir(flysystem://): Cannot remove the root directory
     */
    public function testRemoveRoot()
    {
        // Test without STREAM_REPORT_ERRORS.
        $this->assertFalse(@rmdir('flysystem://'));

        rmdir('flysystem://');
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
        $handle1 = fopen('flysystem://file', 'w');
        $this->assertTrue(flock($handle1, LOCK_EX));
        $this->assertTrue(flock($handle1, LOCK_UN));
    }

    public function testSetOption()
    {
        // Bypass for HHVM.
        if (defined('HHVM_VERSION')) {
            return $this->setOptionHHVM();
        }

        $handle = fopen('flysystem://thing', 'w+');

        $this->assertTrue(stream_set_blocking($handle, 0));
        $this->assertFalse(stream_set_timeout($handle, 10));
        $this->assertSame(-1, stream_set_write_buffer($handle, 100));

        fclose($handle);

        // Test fallthough. Just code coverage nonsense.
        $wrapper = new FlysystemStreamWrapper();
        $this->assertFalse($wrapper->stream_set_option('invallid', 'arguments', 'stuff'));
    }

    public function setOptionHHVM()
    {
        $handle = fopen('flysystem://thing', 'w+');

        // HHVM allows stream_set_blocking() to be called through some other
        // mechanism, not the stream wrapper API, but not the rest of the API.
        $this->assertTrue(stream_set_blocking($handle, 0));
        fclose($handle);
    }

    public function testDirectoryIteration()
    {
        mkdir('flysystem://root');
        mkdir('flysystem://root/one');
        mkdir('flysystem://root/two');
        mkdir('flysystem://root/three');

        $dir = opendir('flysystem://root');
        $this->assertSame('one', readdir($dir));
        $this->assertSame('two', readdir($dir));
        $this->assertSame('three', readdir($dir));

        $this->assertFalse(readdir($dir));

        rewinddir($dir);
        $this->assertSame(readdir($dir), 'one');

        closedir($dir);
    }

    public function testDirectoryIterationRoot()
    {
        mkdir('flysystem://one');
        mkdir('flysystem://two');
        mkdir('flysystem://three');

        $dir = opendir('flysystem://');
        $this->assertSame('one', readdir($dir));
        $this->assertSame('two', readdir($dir));
        $this->assertSame('three', readdir($dir));

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

    public function testReadMode()
    {
        $this->putContent('test_file.txt', 'some file content');
        $handle = fopen('flysystem://test_file.txt', 'rb');
        $this->assertSame('some file content', fread($handle, 100));
        $this->assertSame(0, fwrite($handle, 'more content'));
        $this->assertFalse(ftruncate($handle, 0));
        fclose($handle);

        // Test COW.
        $handle = fopen('flysystem://test_file.txt', 'r+');
        $this->assertSame(0, fseek($handle, 0, SEEK_END));
        $this->assertSame(13, fwrite($handle, ' more content'));
        $this->assertTrue(fflush($handle));

        $this->assertFileContent('test_file.txt', 'some file content more content');
        fclose($handle);

        $handle = fopen('flysystem://test_file.txt', 'r+');
        $this->assertTrue(ftruncate($handle, 4));
        $this->assertTrue(fflush($handle));

        $this->assertFileContent('test_file.txt', 'some');
        fclose($handle);
    }

    public function testAppendMode()
    {
        $this->putContent('test_file.txt', 'some file content');
        $handle = fopen('flysystem://test_file.txt', 'a');

        // ftell() and fseek() are undefined for append files. HHVM enforces
        // this.
        $this->assertSame(0, ftell($handle));
        $this->assertSame(0, fseek($handle, 0));

        // Test write-only.
        $this->assertSame('', fread($handle, 100));

        $this->assertSame(5, fwrite($handle, '12345'));
        fclose($handle);
        $this->assertFileContent('test_file.txt', 'some file content12345');

        $handle = fopen('flysystem://test_file.txt', 'c+');
        $this->assertSame(0, ftell($handle));
        $this->assertSame('some file content12345', fread($handle, 100));
        fclose($handle);

        // Test create file.
        $handle = fopen('flysystem://new_file.txt', 'a');
        $this->assertSame(5, fwrite($handle, '12345'));
        fclose($handle);
        $this->assertFileContent('new_file.txt', '12345');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage fopen(flysystem://new_file.txt): failed to open stream: File exists
     */
    public function testXMode()
    {
        $handle = fopen('flysystem://new_file.txt', 'x+');
        $this->assertSame(5, fwrite($handle, '12345'));
        fclose($handle);
        $this->assertFileContent('new_file.txt', '12345');

        // Returns false.
        $this->assertFalse(@fopen('flysystem://new_file.txt', 'x+'));
        // Throws warning.
        fopen('flysystem://new_file.txt', 'x+');
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
     * @expectedExceptionMessage unlink(flysystem://asdfasdfasf): No such file or directory
     */
    public function testFailedUnlink()
    {
        $this->assertFalse(@unlink('flysystem://asdfasdfasf'));
        unlink('flysystem://asdfasdfasf');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage rename(flysystem://test_file1.txt,flysystem://test_file3.txt): No such file or directory
     */
    public function testBadRename()
    {
        $this->assertFalse(@rename('flysystem://test_file1.txt', 'flysystem://test_file3.txt'));
        rename('flysystem://test_file1.txt', 'flysystem://test_file3.txt');
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage fopen(flysystem://doesnotexist): failed to open stream: No such file or directory
     */
    public function testReadMissing()
    {
        $this->assertFalse(@fopen('flysystem://doesnotexist', 'rbt'));
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

    protected function assertPerm($file, $perm) {
        clearstatcache($this->testDir . '/' . $file);

        $fileperm = fileperms($this->testDir . '/' . $file);

        $this->assertSame($perm, octdec(substr(decoct($fileperm), -4)));
    }
}

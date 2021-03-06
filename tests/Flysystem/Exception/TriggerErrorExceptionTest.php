<?php

use Twistor\Flysystem\Exception\DirectoryExistsException;

class TriggerErrorExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testFormat()
    {
        $e = new DirectoryExistsException();
        $this->assertSame('a(): Is a directory', $e->formatMessage('a'));
    }
}

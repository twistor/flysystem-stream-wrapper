<?php

use Twistor\Uid;

class UidTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $uid = new Uid();

        $info = stat(__FILE__);

        $this->assertSame($info['uid'], $uid->getUid());
        $this->assertSame($info['gid'], $uid->getGid());
    }
}

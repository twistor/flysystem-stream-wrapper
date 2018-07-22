<?php

namespace Twistor;

class PosixUid extends Uid
{
    public function getUid()
    {
        return (int) posix_getuid();
    }

    public function getGid()
    {
        return (int) posix_getuid();
    }
}

<?php

namespace Twistor;

class Uid
{
    public function getUid()
    {
        return (int) getmyuid();
    }

    public function getGid()
    {
        return (int) getmygid();
    }
}

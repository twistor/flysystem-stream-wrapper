<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;

/**
 * An alternate version of the local adapter that doesn't support visibility.
 */
class NoVisibilityLocal extends Local
{
    public function getVisibility($path)
    {
        throw new \LogicException();
    }

    public function setVisibility($path, $visibility)
    {
        throw new \LogicException();
    }
}

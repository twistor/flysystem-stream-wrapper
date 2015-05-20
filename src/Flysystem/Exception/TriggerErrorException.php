<?php

namespace Twistor\Flysystem\Exception;

use League\Flysystem\Exception;

class TriggerErrorException extends Exception
{
    public function formatMessage(array $args)
    {
        return vsprintf($this->message, $args);
    }
}

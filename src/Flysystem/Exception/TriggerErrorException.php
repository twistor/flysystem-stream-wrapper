<?php

namespace Twistor\Flysystem\Exception;

use League\Flysystem\Exception;

class TriggerErrorException extends Exception
{
    protected $defaultMessage;

    public function formatMessage(array $args)
    {
        return vsprintf($this->message ? $this->message : $this->defaultMessage, $args);
    }
}

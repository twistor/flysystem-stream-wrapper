<?php

namespace Twistor\Flysystem\Exception;

use Exception as GlobalException;
class TriggerErrorException extends GlobalException
{
    protected $defaultMessage;

    public function formatMessage($function)
    {
        return sprintf($this->message ? $this->message : $this->defaultMessage, $function);
    }
}

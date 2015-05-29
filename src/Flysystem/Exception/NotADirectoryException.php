<?php

namespace Twistor\Flysystem\Exception;

class NotADirectoryException extends TriggerErrorException
{
    protected $defaultMessage = '%s(): Not a directory';
}

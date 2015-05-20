<?php

namespace Twistor\Flysystem\Exception;

class DirectoryNotEmptyException extends TriggerErrorException
{
    protected $message = '%s(%s): Directory not empty';
}

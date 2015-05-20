<?php

namespace Twistor\Flysystem\Exception;

class NotADirectoryException extends TriggerErrorException
{
    protected $message = '%s(%s): Not a directory';

}

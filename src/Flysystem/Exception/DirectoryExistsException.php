<?php

namespace Twistor\Flysystem\Exception;

class DirectoryExistsException extends TriggerErrorException
{
    protected $message = '%s(%s): Is a directory';

}

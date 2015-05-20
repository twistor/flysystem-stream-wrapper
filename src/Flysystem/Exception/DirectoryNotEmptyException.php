<?php

namespace Twistor\Flysystem\Exception;

class DirectoryNotEmptyException extends TriggerErrorException
{
    protected $defaultMessage = '%s(%s): Directory not empty';
}

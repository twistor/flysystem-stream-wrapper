<?php

namespace Twistor\Flysystem\Exception;

class FileNotFoundException extends TriggerErrorException
{
    protected $defaultMessage = '%s(): Not a file';
}

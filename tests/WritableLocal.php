<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;

/**
 * An alternate version of the local adapter that allows direct writing to the
 * read stream.
 */
class WritableLocal extends Local
{
    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $location = $this->applyPathPrefix($path);
        $handle = fopen($location, 'r');

        $stream = fopen('php://temp', 'w+b');
        stream_copy_to_stream($handle, $stream);
        fclose($handle);
        fseek($stream, 0);

        return compact('stream', 'path');
    }
}

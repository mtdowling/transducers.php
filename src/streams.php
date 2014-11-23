<?php
namespace transducers\streams;

use transducers as t;
use transducers\Reduced;

/**
 * Appends a transducer filter to an open stream.
 *
 * @param resource $stream    Stream to add a filter to.
 * @param callable $xf        Transducer function.
 * @param int      $readWrite Constants available on PHP's stream_filter_append
 *
 * @return resource Returns the appended stream filter resource.
 */
function append_filter($stream, callable $xf, $readWrite)
{
    register_stream_filter();
    return stream_filter_append($stream, 'transducer', $readWrite, $xf);
}

/**
 * Prepends a transducer filter to an open stream.
 *
 * @param resource $stream    Stream to add a filter to.
 * @param callable $xf        Transducer function.
 * @param int      $readWrite Constants available on PHP's stream_filter_prepend
 *
 * @return resource Returns the appended stream filter resource.
 */
function prepend_filter($stream, callable $xf, $readWrite)
{
    register_stream_filter();
    return stream_filter_prepend($stream, 'transducer', $readWrite, $xf);
}

/**
 * Registers the 'transducer' stream filter.
 */
function register_stream_filter()
{
    stream_filter_register('transducer', 'transducers\streams\StreamFilter');
}

/**
 * Implements transducer functionality in PHP stream filters.
 */
class StreamFilter extends \php_user_filter
{
    private $xf;
    private $buffer;
    private $bufferHandle;

    public function onCreate()
    {
        if (!is_callable($this->params)) {
            trigger_error('Filter params arg must be a transducer function');
            return false;
        }

        $reducer = t\create_reducer(function($r, $x) { $this->buffer .= $x; });
        $this->xf = call_user_func($this->params, $reducer);
        return true;
    }

    public function onClose()
    {
        if (is_resource($this->bufferHandle)) {
            fclose($this->bufferHandle);
        }
    }

    function filter($in, $out, &$consumed, $closing)
    {
        $result = '';

        while ($bucket = stream_bucket_make_writeable($in)) {
            // Stream each byte through the step function.
            for ($i = 0, $t = strlen($bucket->data); $i < $t; $i++) {
                $consumed++;
                $result = $this->xf['step']($result, $bucket->data[$i]);
                if ($result instanceof Reduced) {
                    break;
                }
            }
            // A transducer may choose to not use the provided input.
            if (strlen($this->buffer)) {
                $bucket->data = $this->buffer;
                $this->buffer = '';
                stream_bucket_append($out, $bucket);
            }
        }

        // When closing, we allow the $xf['result'] function to add more data.
        if ($closing) {
            $this->xf['result']('');
            if (strlen($this->buffer)) {
                // The buffer is only needed when the result fn calls the step.
                $this->bufferHandle = fopen('php://memory', 'w+');
                $bucket = stream_bucket_new($this->bufferHandle, $this->buffer);
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }
}

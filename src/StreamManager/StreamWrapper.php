<?php

namespace fpoirotte\StreamManager;

use fpoirotte\StreamManager;

class StreamWrapper implements \Countable
{
    public $context;
    protected $rawStream;
    protected $filteredStream;
    protected $outputBuffer = '';

    // @codingStandardsIgnoreStart
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        // @codingStandardsIgnoreEnd
        if (null === $this->context) {
            throw new \InvalidArgumentException('A valid stream context is required');
        }

        $ctxOptions = stream_context_get_options($this->context);
        $wrapper    = StreamManager::WRAPPER_NAME;
        if (!isset($ctxOptions[$wrapper]['stream']) || !self::isStream($ctxOptions[$wrapper]['stream'])) {
            throw new \InvalidArgumentException('No stream specified in context');
        }

        $this->filteredStream = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $this->filteredStream) {
            throw new \RuntimeException('Could not create a wrapper');
        }

        if (false === stream_set_blocking($this->filteredStream[0], false) ||
            false === stream_set_blocking($this->filteredStream[1], false)) {
            throw new \RuntimeException('Could not set streams into non-blocking mode');
        }

        if (array_key_exists('readCallback', $ctxOptions[$wrapper]) &&
            null !== $ctxOptions[$wrapper]['readCallback'] &&
            !is_callable($ctxOptions[$wrapper]['readCallback'])) {
            throw new \InvalidArgumentException('Invalid read callback');
        }

        if (array_key_exists('closeCallback', $ctxOptions[$wrapper]) &&
            null !== $ctxOptions[$wrapper]['closeCallback'] &&
            !is_callable($ctxOptions[$wrapper]['closeCallback'])) {
            throw new \InvalidArgumentException('Invalid close callback');
        }

        $this->rawStream    = $ctxOptions[$wrapper]['stream'];
        $this->filters      = array();
        $opened_path        = $path;
        return true;
    }

    public function count()
    {
        return strlen($this->outputBuffer);
    }

    protected static function isStream($value)
    {
        return is_resource($value) && 'stream' === get_resource_type($value);
    }

    // @codingStandardsIgnoreStart
    public function stream_close()
    {
        // @codingStandardsIgnoreEnd
        fclose($this->filteredStream[0]);
        fclose($this->filteredStream[1]);
        fclose($this->rawStream);
    }

    // @codingStandardsIgnoreStart
    public function stream_eof()
    {
        // @codingStandardsIgnoreEnd
        return feof($this->rawStream);
    }

    // @codingStandardsIgnoreStart
    public function stream_flush()
    {
        // @codingStandardsIgnoreEnd
        return $this->sendOutput() && fflush($this->rawStream);
    }

    // @codingStandardsIgnoreStart
    public function stream_lock($operation)
    {
        // @codingStandardsIgnoreEnd
        return flock($this->rawStream, $operation);
    }

    // @codingStandardsIgnoreStart
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        // @codingStandardsIgnoreEnd
        return !fseek($this->rawStream, $offset, $whence);
    }

    // @codingStandardsIgnoreStart
    public function stream_set_option($option, $arg1, $arg2)
    {
        // @codingStandardsIgnoreEnd
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                return stream_set_blocking($this->rawStream, $arg1);

            case STREAM_OPTION_READ_TIMEOUT:
                // HACK: stream_set_timeout() is used to flush the output buffer.
                if (-1 === $arg1 && -1 === $arg2) {
                    return $this->sendOutput();
                }
                return stream_set_timeout($this->rawStream, $arg1, $arg2);

            case STREAM_OPTION_WRITE_BUFFER:
                return stream_set_write_buffer($this->rawStream, $arg2);

            default:
                throw new \RuntimeException('Invalid option');
        }
    }

    // @codingStandardsIgnoreStart
    public function stream_stat()
    {
        // @codingStandardsIgnoreEnd
        // HACK: we use fstat() to pass information about the output buffer
        //       back to the stream manager.
        return array('size' => strlen($this->outputBuffer));
    }

    // @codingStandardsIgnoreStart
    public function stream_tell()
    {
        // @codingStandardsIgnoreEnd
        return ftell($this->rawStream);
    }

    // @codingStandardsIgnoreStart
    public function stream_truncate($new_size)
    {
        // @codingStandardsIgnoreEnd
        return ftruncate($this->rawStream, $rawSize);
    }

    // @codingStandardsIgnoreStart
    public function stream_read($count)
    {
        // @codingStandardsIgnoreEnd
        $data = fread($this->rawStream, $count);
        if (false === $data) {
            return false;
        }

        while (strlen($data) > 0) {
            $written = fwrite($this->filteredStream[1], $data);
            if (false === $written) {
                return false;
            }
            $data = (string) substr($data, $written);
        }

        $res = fread($this->filteredStream[0], 2 * $count);
        return $res;
    }

    // @codingStandardsIgnoreStart
    public function stream_write($data)
    {
        // @codingStandardsIgnoreEnd
        $res = 0;
        while (strlen($data) > 0) {
            $written = fwrite($this->filteredStream[0], $data);
            if (false === $written) {
                throw new \RuntimeException('Error during write');
            }
            $data = (string) substr($data, $written);
            $res += $written;

            while (false !== ($read = fread($this->filteredStream[1], 8192))) {
                if ('' === $read) {
                    break;
                }
                $this->outputBuffer .= $read;
            }

            if (!$written) {
                break;
            }
        }

        if (false === $this->sendOutput()) {
            throw new \RuntimeException('Error while sending output');
        }
        return $res;
    }

    public function sendOutput()
    {
        while (strlen($this->outputBuffer) > 0) {
            $written = fwrite($this->rawStream, $this->outputBuffer);
            if (false === $written) {
                return false;
            }

            if (!$written) {
                break;
            }

            $this->outputBuffer = (string) substr($this->outputBuffer, $written);
        }
        return true;
    }
}

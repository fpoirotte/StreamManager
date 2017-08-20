<?php

namespace fpoirotte;

class StreamManager implements \ArrayAccess, \Countable
{
    protected $streams;

    const WRAPPER_NAME  = 'streammanager.wrapper';
    const WRAPPER_CLASS = '\\fpoirotte\\StreamManager\\StreamWrapper';

    public function __construct()
    {
        $this->streams = array();
        if (!in_array(static::WRAPPER_NAME, stream_get_wrappers())) {
            if (false === stream_wrapper_register(static::WRAPPER_NAME, static::WRAPPER_CLASS)) {
                throw new \RuntimeException('Could not register stream wrapper');
            }
        }
    }

    public function count()
    {
        return count($this->streams);
    }

    public function offsetExists($offset)
    {
        return isset($this->streams[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->streams[$offset]);
    }

    public function offsetGet($offset)
    {
        if (!isset($this->streams[$offset])) {
            return null;
        }
        return $this->streams[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if (!is_resource($value) || 'stream' !== get_resource_type($value)) {
            throw new \InvalidArgumentException('A stream was expected');
        }

        // Wrap foreign streams
        $meta = stream_get_meta_data($value);
        $uri  = isset($meta['uri']) ? $meta['uri'] : '';
        if (strncasecmp(static::WRAPPER_NAME . ':', $uri, strlen(static::WRAPPER_NAME) + 1)) {
            // We need to use "self" instead of "static" in various places here
            // because that's what the stream wrapper will look for later on.
            $options = stream_context_get_options($value);
            if (false === $options) {
                throw new \RuntimeException('Could not retrieve stream context options');
            }

            $options[self::WRAPPER_NAME]['stream'] = $value;
            if (!isset($options[self::WRAPPER_NAME]['readCallback'])) {
                $options[self::WRAPPER_NAME]['readCallback'] = null;
            }

            if (!isset($options[self::WRAPPER_NAME]['closeCallback'])) {
                $options[self::WRAPPER_NAME]['closeCallback'] = null;
            }

            $ctx    = stream_context_create($options);
            $value  = fopen(static::WRAPPER_NAME . '://', 'r+b', false, $ctx);
            if (false === $value) {
                throw new \RuntimeException('Could not wrap the stream');
            }

            // For some reason, PHP loses track of the options associated
            // with the stream during the call to fopen().
            // Therefore, we bind the options back manually.
            stream_context_set_option($value, $options);
        }

        if (!is_string($offset) || is_numeric($offset)) {
            throw new \InvalidArgumentException('You must assign a name to the stream');
        }
        $this->streams[$offset] = $value;
    }

    public function loopOnce()
    {
        do {
            $r  = array();
            $w  = array();
            $e  = array();

            // Save the current streams in case a callback updates the manager.
            $streams = $this->streams;

            // Prepare the streams select()ion.
            foreach ($this->streams as $name => $stream) {
                $ctx        = stream_context_get_options($stream);
                $rawStream  = $ctx[self::WRAPPER_NAME]['stream'];

                if (!array_key_exists('readCallback', $ctx[self::WRAPPER_NAME])) {
                    throw new \RuntimeException('Invalid read callback');
                }

                if (null !== $ctx[self::WRAPPER_NAME]['readCallback']) {
                    $r[$name] = $rawStream;
                }

                // HACK: fstat() is used to measure the output buffer's size.
                $stat = fstat($stream);
                if ($stat['size'] > 0) {
                    $w[$name] = $rawStream;
                }
            }

            if (!count($r + $w + $e)) {
                return false;
            }

            $nb = @stream_select($r, $w, $e, null, null);
            if (false === $nb) {
                // The call has been interrupted, try again.
                continue;
            }

            if (0 === $nb) {
                // This should never happen since $tv_sec & $tc_usec
                // are both null, ie. stream_select() should wait forever.
                throw new \RuntimeException('Invalid return value from stream_select()');
            }

            foreach ($r as $name => $stream) {
                $cb  = feof($stream) ? 'closeCallback' : 'readCallback';
                $ctx = stream_context_get_options($streams[$name]);

                if (!array_key_exists($cb, $ctx[self::WRAPPER_NAME])) {
                    throw new \RuntimeException("Invalid $cb");
                }

                if (null !== $ctx[self::WRAPPER_NAME][$cb]) {
                    if (!is_callable($ctx[self::WRAPPER_NAME][$cb])) {
                        throw new \RuntimeException("Invalid $cb");
                    }
                
                    call_user_func($ctx[self::WRAPPER_NAME]['readCallback'], $this, $streams[$name], $name);
                } elseif ('closeCallback' === $cb) {
                    // By default, close the stream upon EOF.
                    fclose($streams[$name]);
                    unset($this->streams[$name]);
                }
            }

            foreach (array_keys($w) as $name) {
                // Try to flush the stream's output buffer.
                stream_set_timeout($streams[$name], -1, -1);
            }

            return true;
        } while (true);
    }

    public function loop($iter = 0)
    {
        if (!is_int($iter) || $iter < 0) {
            throw new \InvalidArgumentException('Invalid iteration count');
        }

        if (0 === $iter) {
            do {
                $continue = $this->loopOnce();
            } while ($continue);
            return;
        }

        for ($i = 0; $i < $iter; $i++) {
            if (!$this->loopOnce()) {
                break;
            }
        }
    }
}

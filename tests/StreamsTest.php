<?php

use PHPUnit\Framework\TestCase;
use fpoirotte\StreamManager;

class StreamsTest extends TestCase
{
    protected $buffer = array();

    public function testSingleStreamAndSingleFilter()
    {
        $data = 'Hello world!';

        // Create a stream
        $rawStream  = fopen('php://temp/', 'w+');

        // Add the stream to the manager
        $manager            = new StreamManager();
        $manager['rot13']   = $rawStream;

        stream_filter_append($manager['rot13'], 'string.rot13', STREAM_FILTER_WRITE);
        fwrite($manager['rot13'], $data);

        $manager->loop();

        fseek($rawStream, 0);
        $this->assertSame(str_rot13($data), fread($rawStream, 32));
    }

    public function inc($manager, $stream, $name)
    {
        $data = fread($stream, 32);

        if (!isset($this->buffer[$name])) {
            $this->buffer[$name] = array();
        }
        $this->buffer[$name][] = $data;

        $value = (int) $data;
        if ($value < 9) {
            // We pad the data to 3 characters to prevent base64 padding
            // (which won't be a problem for the first padded-message,
            // but will trigger a decoding failure in messages afterward).
            fwrite($stream, (string) ($value + 1) . "  ");
        }

        if ($value >= 9) {
            unset($manager[$name]);
        }
    }

    public function testBidirectionalCommunications()
    {
        $manager    = new StreamManager();
        $sock       = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach (array(0, 1) as $i) {
            stream_context_set_option($sock[$i], StreamManager::WRAPPER_NAME, 'readCallback', array($this, 'inc'));
            stream_set_blocking($sock[$i], false);
            stream_set_read_buffer($sock[$i], 0);
            stream_set_write_buffer($sock[$i], 0);
        }

        $manager['a2b'] = $sock[0];
        $manager['b2a'] = $sock[1];

        // A sends data to B by first applying base64 then rot13,
        // while B sends data A by first applying rot13 then base64.
        stream_filter_append($manager['a2b'], 'convert.base64-encode', STREAM_FILTER_WRITE);
        stream_filter_append($manager['a2b'], 'string.rot13', STREAM_FILTER_WRITE);

        stream_filter_append($manager['a2b'], 'convert.base64-decode', STREAM_FILTER_READ);
        stream_filter_append($manager['a2b'], 'string.rot13', STREAM_FILTER_READ);

        stream_filter_append($manager['b2a'], 'string.rot13', STREAM_FILTER_WRITE);
        stream_filter_append($manager['b2a'], 'convert.base64-encode', STREAM_FILTER_WRITE);

        stream_filter_append($manager['b2a'], 'string.rot13', STREAM_FILTER_READ);
        stream_filter_append($manager['b2a'], 'convert.base64-decode', STREAM_FILTER_READ);

        // We pad the data to 3 characters to prevent base64 padding
        // (which won't be a problem for the first padded-message,
        // but will trigger a decoding failure in messages afterward).
        fwrite($manager['a2b'], "0  ");
        $manager->loop();

        // "b2a" should have received even numbers from 0 to 9, padded with spaces,
        // while "a2b" should have received odd number (also padded with spaces).
        // Also, since "b2a" is the first one to receive a message (sent by "a2b"),
        // hence the fast that it appears first in the expected output.
        $expected = array(
            "b2a" => array('0  ', '2  ', '4  ', '6  ', '8  '),
            "a2b" => array('1  ', '3  ', '5  ', '7  ', '9  '),
        );
        $this->assertSame($expected, $this->buffer);
    }
}

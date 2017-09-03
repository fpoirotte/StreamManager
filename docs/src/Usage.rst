Usage
=====

The manager's event loop
------------------------

To use the manager, you first need to create an instance for it:

..  sourcecode:: inline-php

    use fpoirotte\StreamManager;

    $manager = new StreamManager();

You can then create a new PHP stream like you would normally,
and pass it to the manager, with a label for later reference:

..  sourcecode:: inline-php

    // Create a new stream and store it in the manager
    // under the label "rot13".
    $rawStream  = fopen('php://temp/', 'w+');
    $manager['rot13']   = $rawStream;

When passed to the manager like this, the stream will actually be wrapped
in a new special stream. This new stream is what is actually stored
in the manager using the given label.

Any operation after that point should be done on the wrapper rather than on
the original (raw) stream. The wrapper can be used to add PHP stream filters,
write/read data, and so on.

..  sourcecode:: inline-php

    // Add a filter so that any data written to the stream
    // gets automatically scrambled using the ROT13 encoding scheme.
    stream_filter_append($manager['rot13'], 'string.rot13', STREAM_FILTER_WRITE);

    // Write some data to the stream (wrapper).
    fwrite($manager['rot13'], "Hello world!");

Once the streams have all been registered, we tell the manager to do its job
until there are no longer any stream to manage:

..  sourcecode:: inline-php

    $manager->loop();

..  note::

    Alternatively, it is possible to control exactly how many times the manager
    will run its event loop, by giving it a maximum iteration count:

    ..  sourcecode:: inline-php

        $manager->loop(10);

    An iteration occurs after an event is received or an error occurs.
    The manager will run in a loop until the given number of iterations
    is reached or there are no more streams left to manage.
    By default, the number of expected iterations is 0,
    which causes the manager to loop endlessly.


Events and callback functions
-----------------------------

The manager relies on callback functions to process certains events.
It also defines default callback functions to handle those events.

The following callback functions are used:

*   ``readCallback``: this function is called when the stream has incoming data
    pending a read.

    There is no default implementation for this callback.
    Depending on the type of stream used, some data may be dropped when PHP's
    internal buffer is filled, or the stream may block until some of the data
    has been read.

*   ``closeCallback``: this function is called when the stream is closed
    by another party (such as a network socket being disconnected by its peer).

    The default implementation simply closes the stream and removes it from
    list of streams currently handled by the stream manager.

To change the callback associated with an event, use the following code snippet.

..  note::

    To have any effect, these options must be set **before** the raw stream
    is registered with the manager.

    Also, some versions of HHVM do not support the use of array options
    with ``stream_context_set_option()``. Thus, it is advised that you set
    each option separately using the long key-value call form.

..  sourcecode:: inline-php

    // Call "onDataReceived" when new data is received.
    stream_context_set_option($rawStream, StreamManager::WRAPPER_NAME, 'readCallback', 'onDataReceived');

    // Call "onStreamClosed" when the stream gets closed by its peer.
    stream_context_set_option($rawStream, StreamManager::WRAPPER_NAME, 'closeCallback', 'onStreamClosed');

Each callback will be called with 3 arguments:

*   The instance of the manager responsible for triggering the event
    (ie. an instance of ``fpoirotte\\StreamManager``)

*   The instance of the stream wrapper associated with the event,
    which can be used with stream-related PHP functions (``fread()``,
    ``fwrite()``, ``fclose()``, and so on)

*   The label attached to the stream wrapper during its registration
    with the stream manager.
    This label can be used, for example, to remove the stream from the list
    of streams currently handled by the stream manager.


The following code snippet is an example of what a ``closeCallback`` might
look like:

..  sourcecode:: inline-php

    function onStreamClosed($manager, $stream, $name)
    {
        // Close the stream on our side too.
        @fclose($stream);

        // Remove the stream from the list of streams handled by the manager.
        unset($manager[$name]);
    }

.. vim: ts=4 et

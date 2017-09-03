Introduction
============

This projects originates from the following facts:

*   PHP streams form a powerful toolbox, with oft-underrated features
    (custom streams, custom filters, etc.)

*   The PHP approach to streams interactions closely follows that of C,
    to the point that the function names are often the same (``fread()``,
    ``fwrite()``, etc.)

    Developpers who are not accustomed to C may find it difficult to
    work with PHP streams (eg. dealing with partial reads/writes).

*   Though powerful, the API can be quite difficult to master
    for beginners or even seasoned developers.
    The implementation also comes with several annoying limitations,

    For example, the ``stream_select()`` function is very useful when working
    with non-blocking streams, until you start using stream filters and realize
    that it does not work for filtered strems.

    This can lead to confusion or even frustration on the part of
    PHP developers.

*   Last but not least, combining multiple projects each using streams
    can prove to be a real challenge (eg. each project expects to be able
    to run an endless ``stream_select()`` loop, leaving no way for other
    libraries to do their share of the job)

The PHP stream manager was created as an attempt to get rid of some of these
hindrances by providing:

*   A common framework for libraries to build upon, so that multiple projects
    can be used together without fear of conflicts related to stream management.

*   A simple approach to stream management, using callback functions
    to handle various events (incoming data, disconnections, ...)

*   An abstraction layer that adds support for filtered streams in
    ``stream_select()``, while still retaining the original PHP stream API
    (eg. so that things like ``stream_filter_append()`` still work the same way)

.. vim: ts=4 et


===============
transducers-php
===============

`Transducers <http://clojure.org/transducers>`_ are composable algorithmic
transformations. They are independent from the context of their input and
output sources and specify only the essence of the transformation in terms of
an individual element. Because transducers are decoupled from input or output
sources, they can be used in many different processes - collections, streams,
channels, observables, etc. Transducers compose directly, without awareness of
input or creation of intermediate aggregates.

In PHP, you can transduce anything that you can iterate over in a foreach-loop
(e.g., arrays, ``\Iterator``, ``Traversable``, ``Generator``, etc.).

For more information about Clojure transducers and transducer semantics see the
introductory `blog post <http://blog.cognitect.com/blog/2014/8/6/transducers-are-coming>`_
and this `video <https://www.youtube.com/watch?v=6mTbuzafcII>`_.

Installation
------------

@TODO: Put on Packagist

Usage
-----

.. code-block:: php

    require 'vendor/autoload.php';

    use Transducers as T;

    $result = T\transduce(
        T\comp([
            // Flatten the sequence
            T\mapcat('Transducers\identity'),
            // Multiply each value * 2
            T\map(function ($value) {
                return $value * 2;
            }),
            // Stop when a value is >= 20
            T\take_while(function ($value) {
                return $value < 20;
            }),
            // Remove the first two entries
            T\drop(2)
        ]),
        // Append each element to an array.
        T\append(),
        // Data to filter
        [[1, 2, 3], [4, 5], [6], [], [7], [8, 9, 10, 11]]
    );

    var_export($result);
    //> [6, 8, 10, 12, 14, 16, 18]

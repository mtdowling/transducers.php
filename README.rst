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

You can transduce anything that you can iterate over in a foreach-loop (e.g.,
arrays, ``\Iterator``, ``Traversable``, ``Generator``, etc.). Transducers can
be applied eagerly using ``transduce()`` or ``into()`` and lazily using
``iter()`` or ``seq()``.

For more information about Clojure transducers and transducer semantics see the
introductory `blog post <http://blog.cognitect.com/blog/2014/8/6/transducers-are-coming>`_
and this `video <https://www.youtube.com/watch?v=6mTbuzafcII>`_.

::

    composer.phar install mtdowling/transducers-php

.. code-block:: php

    require 'vendor/autoload.php';

    use Transducers as T;

    // Compose a transducer function.
    $transducer = T\comp(
        // Remove one level of array nesting.
        T\cat(),
        // Filter out even values.
        T\filter(function ($value) {
            return $value % 2;
        }),
        // Multiply each value by 2
        T\map(function ($value) {
            return $value * 2;
        }),
        // Immediately stop when the value is >= 15.
        T\take_while(function($value) {
            return $value < 15;
        })
    );

    $data = [[1, 2, 3], [4, 5], [6], [], [7], [8, 9, 10, 11]];

    // Eagerly pour the transformed data into an array.
    $result = T\into([], $transducer, $data);
    // Result contains: [2, 6, 10, 14]

    // Lazily transform the data. Outputs: 2, 6, 10, 14,
    foreach (T\iter($data, $transducer) as $value) {
        echo $value . ', ';
    }

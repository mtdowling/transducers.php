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

::

    composer.phar install mtdowling/transducers-php

For more information about Clojure transducers and transducer semantics see the
introductory `blog post <http://blog.cognitect.com/blog/2014/8/6/transducers-are-coming>`_
and this `video <https://www.youtube.com/watch?v=6mTbuzafcII>`_.

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

You can transduce anything that you can iterate over in a foreach-loop (e.g.,
arrays, ``\Iterator``, ``Traversable``, ``Generator``, etc.). Transducers can
be applied eagerly using ``transduce()`` or ``into()`` and lazily using
``iter()`` or ``seq()``.

Transducers
-----------

``map(callable $f)``
    Applies a map function ``$f`` to each value in a collection.

``filter(callable $pred)``
     Filters values that do not satisfy the predicate function ``$pred``.

``remove(callable $pred)``
    Removes anything from a sequence that satisfied ``$pred``.

``cat()``
    Concatenates items from nested lists.

``mapcat(callable $f)``
    Applies a map function to a collection and cats them into one less level of
    nesting.

``chunk($size)``
    Chunks the input sequence into chunks of the specified size.

``take(callable $pred)``
    Takes ``$n`` number of values from a collection.

``take_while(callable $pred)``
    Takes from a collection while the predicate function ``$pred`` returns
    true.

``take_nth($nth)``
    Takes every nth item from a sequence of values.

``drop($n)``
    Drops ``$n`` items from the beginning of the input sequence.

``drop_while(callable $pred)``
    Drops values from a sequence so long as the predicate function ``$pred``
    returns true.

``replace(array $smap)``
    Given a map of replacement pairs and a collection, returns a sequence where
    any elements equal to a key in ``$smap`` are replaced with the
    corresponding ``$smap`` value.

``keep(callable $f)``
    Keeps $f items for which $f does not return null.

``keep_indexed(callable $f)``
    Returns a sequence of the non-null results of ``$f($index, $input)``.

``dedupe()``
    Removes duplicates that occur in order (keeping the first in a sequence of
    duplicate values).

``interpose($separator)``
    Adds a separator between each item in the sequence.

Utility Functions
-----------------

``compose(...callable $args)``
    Composes the provided variadic function arguments into a single function.

``create(callable $init, callable $step, callable $complete)``
    Creates a transducer function that executes functions for initializing a
    transducer that accepts no arguments and returns an initial value, a step
    function that accepts (``$result``, ``$input``) and returns the reduced
    result, and a complete function that accepts a ``$result`` and returns the
    final ``$result``.

``into($target, callable $xf, $coll)``
    Transduces items from ``$coll`` into the given ``$target``, in essence
    "pouring" transformed data from one source into another data type.

``iter()``
    Lazily applies the transducer ``$xf`` to the $input iterator.

``seq()``
    Returns the same data type passed in as ``$coll`` with ``$xf`` applied.

``append()``
    Creates a transducer step function that appends values to an array.

``stream()``
    Creates a transducer step function that writes values to a stream resource.

``identity($x)``
    Always returns the provided value.

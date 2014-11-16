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

You can transduce anything that you can iterate over in a foreach-loop (e.g.,
arrays, ``\Iterator``, ``Traversable``, ``Generator``, etc.). Transducers can
be applied **eagerly** using ``transduce()`` or ``into()`` and **lazily** using
``iter()`` or ``seq()``.

Defining Transformations With Transducers
-----------------------------------------

Transducers compose with ordinary function composition. A transducer performs
its operation before deciding whether and how many times to call the transducer
it wraps. The recommended way to compose transducers is with the
``Transducers\comp()`` function:

.. code-block:: php

    use Transducers as T;
    $xf = T\comp(T\drop(2), T\take(5));

The above composed transducer skips the first two elements of a collection then
takes 5 elements from the collection. This new transformation function can
be used with ``Transducers\transduce()``, ``Transducers\iter()``,
``Transducers\into()``, and ``Transducers\seq()``.

.. code-block:: php

    T\seq([1, 2, 3, 4, 5, 6, 7, 8], $xf);
    // Returns: [3, 4, 5, 6, 7]

Using Transducers
-----------------

Transducers can be used in any number of ways. This library provides several
methods that can be used to apply transducers.

transduce()
~~~~~~~~~~~

.. code-block:: php

    function transduce(callable $xf, callable $step, $coll, $init = null)

Reduce with a transformation of f (xf).

* ``$xf``: Transducer to apply.
* ``$step``: Reducing step function to invoke.
* ``$coll``: Data to transform. Can be an array, iterator, or PHP stream
  resource.
* ``$init``: Optional first initialization value of the reduction.

When using this function, you can use two built-in reducing step functions:

* ``Transducers\append()``: Creates a transducer step function that appends
  values to an array.
* ``stream()``: Creates a transducer step function that writes values to a
  stream resource. If no ``$init`` value is provided when transducing then
  a PHP temp stream will be used.

.. code-block:: php

    use Transducers as T;

    $result = T\transduce(
        T\comp(
            T\cat(),
            T\filter(function ($value) {
                return $value % 2;
            }),
        ),
        T\append(),
        [[1, 2], [3, 4]]
    );

    // Contains: [1, 3]

into()
~~~~~~

.. code-block:: php

    function into($target, callable $xf, $coll)

Transduces items from ``$coll`` into the given ``$target``, in essence
"pouring" transformed data from one source into another data type.

.. code-block:: php

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

    // Eagerly pour the transformed data, [2, 6, 10, 14], into an array.
    $result = T\into([], $transducer, $data);

iter()
~~~~~~

.. code-block:: php

    function iter($coll, callable $xf)

Creates an iterator that **lazily** applies the transducer ``$xf`` to the
``$input`` iterator. Use this function when dealing with large amounts of data
or when you want operations to occur only as needed.

.. code-block:: php

    // Generator that yields incrementing numbers.
    $forever = function () {
        $i = 0;
        while (true) {
            yield $i++;
        }
    };

    // Create a transducer that multiplies each value by two and takes
    // ony 100 values.
    $xf = T\comp(
        T\map(function ($value) {
            return $value * 2;
        }),
        T\take(100)
    );

    // T\iter() returns an iterator that applies $xf lazily.
    $iterator = T\iter($forever(), $transducer);

    foreach ($iterator as $value) {
        echo $value;
    }

seq()
~~~~~

.. code-block:: php

    function seq($coll, callable $xf)

Returns the same data type passed in as ``$coll`` with ``$xf`` applied. When
``$coll`` is an array, ``seq`` will pour that transformed data from ``$coll``
into an array. When ``$coll`` is an iterator, ``seq`` will read from ``$coll``
lazily and create an iterator that applies ``$xf`` to each yielded value.

Available Transducers
---------------------

map()
~~~~~

.. code-block:: php

    function map(callable $f)

Applies a map function ``$f`` to each value in a collection.

filter()
~~~~~~~~

.. code-block:: php

    function filter(callable $pred)

Filters values that do not satisfy the predicate function ``$pred``.

remove()
~~~~~~~~

.. code-block:: php

    function remove(callable $pred)

Removes anything from a sequence that satisfied ``$pred``.

cat()
~~~~~

.. code-block:: php

    function cat()

Concatenates items from nested lists.

mapcat()
~~~~~~~~

.. code-block:: php

    function mapcat(callable $f)

Applies a map function to a collection and cats them into one less level of
nesting.

chunk()
~~~~~~~

.. code-block:: php

    function chunk($size)

Chunks the input sequence into chunks of the specified size.

take()
~~~~~~

.. code-block:: php

    function take($n);

Takes ``$n`` number of values from a collection.

take_while()
~~~~~~~~~~~~

.. code-block:: php

    function take_while(callable $pred)

Takes from a collection while the predicate function ``$pred`` returns true.

take_nth()
~~~~~~~~~~

.. code-block:: php

    function take_nth($nth)

Takes every nth item from a sequence of values.

drop()
~~~~~~

.. code-block:: php

    function drop($n)

Drops ``$n`` items from the beginning of the input sequence.

drop_while()
~~~~~~~~~~~~

.. code-block:: php

    function drop_while(callable $pred)

Drops values from a sequence so long as the predicate function ``$pred``
returns true.

replace()
~~~~~~~~~

.. code-block:: php

    function replace(array $smap)

Given a map of replacement pairs and a collection, returns a sequence where any
elements equal to a key in ``$smap`` are replaced with the corresponding
``$smap`` value.

keep()
~~~~~~

.. code-block:: php

    function keep(callable $f)

Keeps ``$f`` items for which ``$f`` does not return null.

keep_indexed()
~~~~~~~~~~~~~~

.. code-block:: php

    function keep_indexed(callable $f)

Returns a sequence of the non-null results of ``$f($index, $input)``.

dedupe()
~~~~~~~~

.. code-block:: php

    function dedupe()

Removes duplicates that occur in order (keeping the first in a sequence of
duplicate values).

interpose()
~~~~~~~~~~~

.. code-block:: php

    function interpose($separator)

Adds a separator between each item in the sequence.

Creating Transducers
--------------------

Transducers are functions that accept a transformation function ``$xf`` and
return a new function that uses the provided ``$xf`` function and behaves
differently based on arity (number of arguments).

The recommended way to create a transducer is to use the ``create()`` function.
Here's how to create a mapping transducer that adds 1 to each value:

.. code-block:: php

    $f = function ($value) {
        return $value + 1;
    }

    $inc = function (callable $step) use ($f) {
        return T\create(
            // Call the step function with the provided arguments.
            $step,
            // Reduce function that calls the step function.
            function ($result, $input) use ($step, $f) {
                return $step($result, $f($input));
            },
            // Call the step function with the provided arguments.
            $step
        );
    };

    $result = T\into([], $inc, [1, 2, 3]); // Contains: 2, 3, 4

The ``create`` function has the following signature:

.. code-block:: php

    function create(callable $init, callable $step, callable $complete)

* ``callable $init`` (arity 0): Function invoked with no arguments to
  initialize a transducer. Should call the init arity on the nested transform
  ``$xf``, which will eventually call out to the transducing process.
* ``callable $step`` (arity 2): Function called with two arguments. This is a
  standard reduction function but it is expected to call the ``$xf`` step arity
  0 or more times as appropriate in the transducer. For example, filter will
  choose (based on the predicate) whether to call ``$xf`` or not. map will
  always call it exactly once. cat may call it many times depending on the
  inputs.
* ``callable $complete`` (arity 1): Function called with a single argument.
  Some processes will not end, but for those that do (like transduce), the
  completion arity is used to produce a final value and/or flush state. This
  arity must call the ``$xf`` completion arity exactly once.

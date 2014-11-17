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

For more information about Clojure transducers and transducer semantics see the
introductory `blog post <http://blog.cognitect.com/blog/2014/8/6/transducers-are-coming>`_
and this `video <https://www.youtube.com/watch?v=6mTbuzafcII>`_.

You can transduce anything that you can iterate over in a foreach-loop (e.g.,
arrays, ``\Iterator``, ``Traversable``, ``Generator``, etc.). Transducers can
be applied **eagerly** using ``transduce()`` or ``into()`` and **lazily** using
``iter()`` or ``seq()``.

::

    composer.phar require mtdowling/transducers

Defining Transformations With Transducers
-----------------------------------------

Transducers compose with ordinary function composition. A transducer performs
its operation before deciding whether and how many times to call the transducer
it wraps. You can easily compose transducers to create transducer pipelines.
The recommended way to compose transducers is with the ``Transducers\comp()``
function:

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

Transducers
-----------

Transducers are functions that return a function that accept a transformer
array ``$xf`` and return a new transformer array that wraps the provided
``$xf`` transformer array.

Here's how to create a transducer that adds ``$n`` to each value:

.. code-block:: php

    function inc($n = 1) {
        // Return a function that accepts a transformer array $xf.
        return function (array $xf) use ($n) {
            // Return a new transformer array that wraps $xf.
            return [
                'init'   => $xf['init'],
                'result' => $xf['result'],
                'step'   => function ($result, $input) use ($xf, $n) {
                    return $xf['step']($result, $input + $n);
                }
            ];
        }
    };

    $result = T\into([], $inc(1), [1, 2, 3]); // Contains: 2, 3, 4

.. _transformer-link:

Transformer Array
-----------------

Transformer arrays are PHP associative arrays that contain a 'init', 'step',
and 'result' key that maps to a function.

+--------+-------------------------+------------------------------------------+
|   key  |        arguments        |                  Description             |
+========+=========================+==========================================+
|  init  |           none          | Invoked to initialize a transducer. This |
|        |                         | function should call the 'init' function |
|        |                         | on the nested transformer array ``$xf``, |
|        |                         | which will eventually call out to the    |
|        |                         | transducing process. This function is    |
|        |                         | only called when an initial value is not |
|        |                         | provided while transducing.              |
+--------+-------------------------+------------------------------------------+
|  step  | ``$result``, ``$input`` | This is a standard reduction function    |
|        |                         | but it is expected to call the           |
|        |                         | ``$xf['step']`` function 0 or more       |
|        |                         | times as appropriate in the transducer.  |
|        |                         | For example, ``filter`` will choose      |
|        |                         | (based on the predicate) whether to call |
|        |                         | ``$xf`` or not. ``map`` will always call |
|        |                         | it exactly once. ``cat`` may call it     |
|        |                         | many times depending on the inputs.      |
+--------+-------------------------+------------------------------------------+
| result |       ``$result``       | Some processes will not end, but for     |
|        |                         | those that do (like transduce), the      |
|        |                         | 'result' function is used to produce     |
|        |                         | a final value and/or flush state. This   |
|        |                         | function must call the ``$xf['result']`` |
|        |                         | function exactly once.                   |
+--------+-------------------------+------------------------------------------+

Using Transducers
-----------------

Transducers can be used in any number of ways. This library provides several
methods that can be used to apply transducers.

transduce()
~~~~~~~~~~~

``function transduce(callable $xf, array $step, $coll, $init = null)``

Transform and reduce $coll by applying $xf($step)['step'] to each value.

- ``callable $xf``: Transducer function to apply.
- ``array $step``: Transformer array that has 'init', 'result', and 'step' keys
  that map to a callable.
- ``$coll``: Data to transform. Can be an array, iterator, or PHP stream
  resource.
- ``$init``: Optional first initialization value of the reduction.

When using this function, you can use two built-in transformation functions as
the ``$step`` argument:

- ``Transducers\append()``: Creates a transformer step function that appends
  values to an array.
- ``stream()``: Creates a transformer that writes values to a stream resource.
  If no ``$init`` value is provided when transducing then a PHP temp stream
  will be used.

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

``function into($target, callable $xf, $coll)``

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

``function iter($coll, callable $xf)``

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
    $iterator = T\iter($forever(), $xf);

    foreach ($iterator as $value) {
        echo $value;
    }

seq()
~~~~~

``function seq($coll, callable $xf)``

Returns the same data type passed in as ``$coll`` with ``$xf`` applied. When
``$coll`` is an array, ``seq`` will pour that transformed data from ``$coll``
into an array. When ``$coll`` is an iterator, ``seq`` will read from ``$coll``
lazily and create an iterator that applies ``$xf`` to each yielded value.

Available Transducers
---------------------

map()
~~~~~

``function map(callable $f)``

Applies a map function ``$f`` to each value in a collection.

.. code-block:: php

    $data = ['a', 'b', 'c'];
    $xf = T\map(function ($value) { return strtoupper($value); });
    assert(T\into([], $xf, $data) == ['A', 'B', 'C']);

filter()
~~~~~~~~

``function filter(callable $pred)``

Filters values that do not satisfy the predicate function ``$pred``.

remove()
~~~~~~~~

``function remove(callable $pred)``

Removes anything from a sequence that satisfied ``$pred``.

cat()
~~~~~

``function cat()``

Concatenates items from nested lists.

mapcat()
~~~~~~~~

``function mapcat(callable $f)``

Applies a map function to a collection and cats them into one less level of
nesting.

partition()
~~~~~~~~~~~

``function partition($size)``

Partitions the source into arrays of size ``$size``. When transformer
completes, the array will be stepped with any remaining items.

take()
~~~~~~

``function take($n);``

Takes ``$n`` number of values from a collection.

take_while()
~~~~~~~~~~~~

``function take_while(callable $pred)``

Takes from a collection while the predicate function ``$pred`` returns true.

take_nth()
~~~~~~~~~~

``function take_nth($nth)``

Takes every nth item from a sequence of values.

drop()
~~~~~~

``function drop($n)``

Drops ``$n`` items from the beginning of the input sequence.

drop_while()
~~~~~~~~~~~~

``function drop_while(callable $pred)``

Drops values from a sequence so long as the predicate function ``$pred``
returns true.

replace()
~~~~~~~~~

``function replace(array $smap)``

Given a map of replacement pairs and a collection, returns a sequence where any
elements equal to a key in ``$smap`` are replaced with the corresponding
``$smap`` value.

keep()
~~~~~~

``function keep(callable $f)``

Keeps ``$f`` items for which ``$f`` does not return null.

keep_indexed()
~~~~~~~~~~~~~~

``function keep_indexed(callable $f)``

Returns a sequence of the non-null results of ``$f($index, $input)``.

dedupe()
~~~~~~~~

``function dedupe()``

Removes duplicates that occur in order (keeping the first in a sequence of
duplicate values).

.. code-block:: php

    $data = ['a', 'b', 'b', 'c', 'c', 'c', 'b'];
    $result = T\into([], T\dedupe(), $data);
    assert($result == ['a', 'b', 'c', 'b']);

interpose()
~~~~~~~~~~~

``function interpose($separator)``

Adds a separator between each item in the sequence.

.. code-block:: php

    $result = T\into([], T\interpose('-'), ['a', 'b', 'c']);
    assert($result == ['a', '-', 'b', '-', 'c']);

tap()
~~~~~

``function tap(callable $interceptor)``

Invokes interceptor with each result and item, and then steps through
unchanged.

The primary purpose of this method is to "tap into" a method chain, in order
to perform operations on intermediate results within the chain. Executes
interceptor with current result and item.

.. code-block:: php

    $data = ['a', 'b', 'c'];

    // echo each value as it passes through the tap function.
    $tap = T\tap(function ($r, $x) {
        echo $x . ', ';
    });

    $xf = T\comp(
        $tap,
        T\map(function ($v) { return strtoupper($v); }),
        $tap
    );

    T\into([], $xf, $data);
    // Prints: a, A, b, B, c, C,

compact()
~~~~~~~~~

``function compact()``

Trim out all falsey values.

.. code-block:: php

    $result = T\into([], T\compact(), ['a', true, false, 'b', 0]);
    assert($result = ['a', true, 'b']);

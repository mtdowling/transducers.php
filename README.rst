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
be applied **eagerly** using ``transduce()``, ``into()``, ``to_array()``,
``to_assoc()``, ``to_string()``; and **lazily** using ``to_iter()`` or
``seq()``.

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

    use Transducers as t;

    $xf = t\comp(
        t\drop(2),
        t\map(function ($x) { return $x + 1; },
        t\filter(function ($x) { return $x % 2; },
        t\take(3)
    );

The above composed transducer is a function that creates a pipeline for
transforming data: it skips the first two elements of a collection,
adds 1 to each value, filters out even numbers, then takes 3 elements from the
collection. This new transformation function can be used with various
transducer application functions, including ``seq()``.

.. code-block:: php

    $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    $result = t\seq($data, $xf);

    // Contains: [5, 7, 9]

Transducers
-----------

Transducers are functions that return a function that accept a reducing
function array ``$xf`` and return a new reducing function array that wraps the
provided ``$xf``.

Here's how to create a transducer that adds ``$n`` to each value:

.. code-block:: php

    function inc($n = 1) {
        // Return a function that accepts a reducing function array $xf.
        return function (array $xf) use ($n) {
            // Return a new reducing function array that wraps $xf.
            return [
                'init'   => $xf['init'],
                'result' => $xf['result'],
                'step'   => function ($result, $input) use ($xf, $n) {
                    return $xf['step']($result, $input + $n);
                }
            ];
        }
    };

    $result = t\seq([1, 2, 3], $inc(1));
    // Contains: 2, 3, 4

.. _reducing-link:

Reducing Function Array
-----------------------

Reducing function arrays are PHP associative arrays that contain a 'init',
'step', and 'result' key that maps to a function.

+--------+-------------------------+------------------------------------------+
|   key  |        arguments        |                  Description             |
+========+=========================+==========================================+
|  init  |           none          | Invoked to initialize a transducer. This |
|        |                         | function should call the 'init' function |
|        |                         | on the nested reducing function array    |
|        |                         |  ``$xf``, which will eventually call out |
|        |                         | to the transducing process. This function|
|        |                         | is only called when an initial value is  |
|        |                         | not provided while transducing.          |
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
- ``$init``: Optional first initialization value of the reduction. If this
  value is not provided, the ``$step['init']()`` function will be called to
  provide a default value.

When using this function, you can use any of the built-in reducing function
arrays as the ``$step`` argument:

- ``Transducers\array_reducer()``: Creates a reducing function array that
  appends values to an array.
- ``Transducers\stream_reducer()``: Creates a reducing function array that
  writes values to a stream resource. If no ``$init`` value is provided when
  transducing then a PHP temp stream will be used.
- ``Transducers\string_reducer()``: Creates a reducing function array that
  concatenates each value to a string.
- ``Transducers\assoc_reducer()``: Creates a reducing function array that adds
  key value pairs to an associative array. Each value must be an array that
  contains the array key in the first element and the array value in the second
  element.
- ``Transducers\create_reducer()``: Convenience function that can be used to
  quickly create reducing function arrays. The first and only required argument
  is a step function that takes the accumulated result and the new value and
  returns a single result. The next, optional, argument is the init function
  that takes no arguments an returns an initialized result. The next, optional,
  argument is the result function which takes a single result argument and is
  expected to return a final result.

.. code-block:: php

    use Transducers as t;

    $result = t\transduce(
        t\comp(
            t\cat(),
            t\filter(function ($value) { return $value % 2; }),
        ),
        t\array_reducer(),
        [[1, 2], [3, 4]]
    );

    // Contains: [1, 3]

into()
~~~~~~

``function into($target, callable $xf, $coll)``

Transduces items from ``$coll`` into the given ``$target``, in essence
"pouring" transformed data from one source into another data type.

This function does not attempt to discern between arrays and associative
arrays. Any array or ArrayAccess object provided will be treated as an
indexed array. When a string is provided, each value will be concatenated to
the end of the string with no separator. When an fopen resource is provided,
data will be written to the end of the stream with no separator between
writes.

.. code-block:: php

    use Transducers as t;

    // Compose a transducer function.
    $transducer = t\comp(
        // Remove one level of array nesting.
        t\cat(),
        // Filter out even values.
        t\filter(function ($value) { return $value % 2; }),
        // Multiply each value by 2
        t\map(function ($value) { return $value * 2; }),
        // Immediately stop when the value is >= 15.
        t\take_while(function($value) { return $value < 15; })
    );

    $data = [[1, 2, 3], [4, 5], [6], [], [7], [8, 9, 10, 11]];

    // Eagerly pour the transformed data, [2, 6, 10, 14], into an array.
    $result = t\into([], $transducer, $data);

to_iter()
~~~~~~~~~

``function to_iter($coll, callable $xf)``

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
    $xf = t\comp(
        t\map(function ($value) { return $value * 2; }),
        t\take(100)
    );

    foreach (t\to_iter($forever(), $xf) as $value) {
        echo $value;
    }

to_array()
~~~~~~~~~~

``function to_array($iterable, callable $xf)``

Converts a value to an array and applies a transducer function. ``$iterable``
is passed through ``vec()`` in order to convert the input value into an array.

.. code-block:: php

    .. code-block:: php

    $result = t\to_array(
        'abc',
        t\map(function ($v) { return strtoupper($v); }
    );

    // Contains: ['A', 'B', 'C']

to_assoc()
~~~~~~~~~~

``function to_assoc($iterable, callable $xf)``

Creates an associative array using the provided input while applying
``$xf`` to each value. Values are converted to arrays that contain the
array key in the first element and the array value in the second.

.. code-block:: php

    $result = t\to_assoc(
        ['a' => 1, 'b' => 2],
        t\map(function ($v) { return [$v[0], $v[1] + 1]; }
    );

    assert($result == ['a' => 2, 'b' => 3]);

to_string()
~~~~~~~~~~~

``function to_string($iterable, callable $xf)``

Converts a value to a string and applies a transducer function to each
character. ``$iterable`` is passed through ``vec()`` in order to convert the
input value into an array.

.. code-block:: php

    echo t\to_string(
        ['a', 'b', 'c'],
        t\map(function ($v) { return strtoupper($v); }
    );

    // Outputs: ABC

seq()
~~~~~

``function seq($coll, callable $xf)``

Returns the same data type passed in as ``$coll`` with ``$xf`` applied.

``seq()`` using the following logic when returning values:

- ``array``: Returns an array using the provided array.
- ``associative array``: Turn the provided array into an indexed array, meaning
  that each value passed to the ``step`` reduce function is an array where
  the first element is the key and the second element is the value. When
  completed, ``seq()`` returns an associative array.
- ``\Iterator``: Returns an iterator in which ``$xf`` is applied lazily.
- ``resource``: Reads single bytes from the provided value and returns a new
  fopen resource that contains the bytes from the input resource after applying
  ``$xf``.
- ``string``: Passes each character from the string through to each step
  function and returns a string.

.. code-block:: php

    // Give an array and get back an array
    $result = t\seq([1, false, 3], t\compact());
    assert($result === [1, 3]);

    // Give an iterator and get back an iterator
    $result = t\seq(new ArrayIterator([1, false, 3]), t\compact());
    assert($result instanceof \Iterator);

    // Give a stream and get back a stream.
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, '012304');
    rewind($stream);
    $result = t\seq($stream, t\compact());
    assert($result == '1234');

    // Give a string and get back a string
    $result = t\seq('abc', t\map(function ($v) { return strtoupper($v); }));
    assert($result === 'abc');

    // Give an associative array and get back an associative array.
    $data = ['a' => 1, 'b' => 2];
    $result = t\seq('abc', t\map(function ($v) {
        return [strtoupper($v[0]), $v[1]];
    }));
    assert($result === ['A' => 1, 'B' => 2]);

Available Transducers
---------------------

map()
~~~~~

``function map(callable $f)``

Applies a map function ``$f`` to each value in a collection.

.. code-block:: php

    $data = ['a', 'b', 'c'];
    $xf = t\map(function ($value) { return strtoupper($value); });
    assert(t\seq($data, $xf) == ['A', 'B', 'C']);

filter()
~~~~~~~~

``function filter(callable $pred)``

Filters values that do not satisfy the predicate function ``$pred``.

.. code-block:: php

    $data = [1, 2, 3, 4];
    $odd = function ($value) { return $value % 2; };
    $result = t\seq($data, t\filter($odd));
    assert($result == [1, 3]);

remove()
~~~~~~~~

``function remove(callable $pred)``

Removes anything from a sequence that satisfied ``$pred``.

.. code-block:: php

    $data = [1, 2, 3, 4];
    $odd = function ($value) { return $value % 2; };
    $result = t\seq($data, t\remove($odd));
    assert($result = [2, 4]);

cat()
~~~~~

``function cat()``

Concatenates items from nested lists.

.. code-block:: php

    $data = [[1, 2], [3], [], [4, 5]];
    $result = t\seq($data, t\cat());
    assert($result == [1, 2, 3, 4, 5]);

mapcat()
~~~~~~~~

``function mapcat(callable $f)``

Applies a map function to a collection and concats them into one less level of
nesting.

.. code-block:: php

    $data = [[1, 2], [3], [], [4, 5]];
    $xf = t\mapcat(function ($value) { return array_sum($value); });
    $result = t\seq($data, $xf);
    assert($result == [3, 3, 0, 9]);

partition()
~~~~~~~~~~~

``function partition($size)``

Partitions the source into arrays of size ``$size``. When the reducing function
array completes, the array will be stepped with any remaining items.

.. code-block:: php

    $data = [1, 2, 3, 4, 5];
    $result = t\seq($data, t\partition(2));
    assert($result == [[1, 2], [3, 4], [5]]);

partition_by()
~~~~~~~~~~~~~~

``function partition_by(callable $pred)``

Split inputs into lists by starting a new list each time the predicate passed
in evaluates to a different condition (true/false) than what holds for the
present list.

.. code-block:: php

    $data = [['a', 1], ['a', 2], [2, 3], ['c', 4]];
    $xf = t\partition_by(function ($v) { return is_string($v[0]); });
    $result = t\into([], $xf, $data);

    assert($result == [
        [['a', 1], ['a', 2]],
        [[2, 3]],
        [['c', 4]]
    ]);

take()
~~~~~~

``function take($n);``

Takes ``$n`` number of values from a collection.

.. code-block:: php

    $data = [1, 2, 3, 4, 5];
    $result = t\seq($data, t\take(2));
    assert($result == [1, 2]);

take_while()
~~~~~~~~~~~~

``function take_while(callable $pred)``

Takes from a collection while the predicate function ``$pred`` returns true.

.. code-block:: php

    $data = [1, 2, 3, 4, 5];
    $xf = t\take_while(function ($value) { return $value < 4; });
    $result = t\seq($data, $xf);
    assert($result == [1, 2, 3]);

take_nth()
~~~~~~~~~~

``function take_nth($nth)``

Takes every nth item from a sequence of values.

.. code-block:: php

    $data = [1, 2, 3, 4, 5, 6];
    $result = t\seq($data, t\take_nth(2));
    assert($result == [1, 3, 5]);

drop()
~~~~~~

``function drop($n)``

Drops ``$n`` items from the beginning of the input sequence.

.. code-block:: php

    $data = [1, 2, 3, 4, 5];
    $result = t\seq($data, t\drop(2));
    assert($result == [3, 4, 5]);

drop_while()
~~~~~~~~~~~~

``function drop_while(callable $pred)``

Drops values from a sequence so long as the predicate function ``$pred``
returns true.

.. code-block:: php

    $data = [1, 2, 3, 4, 5];
    $xf = t\drop_while(function ($value) { return $value < 3; });
    $result = t\seq($data, $xf);
    assert($result == [3, 4, 5]);

replace()
~~~~~~~~~

``function replace(array $smap)``

Given a map of replacement pairs and a collection, returns a sequence where any
elements equal to a key in ``$smap`` are replaced with the corresponding
``$smap`` value.

.. code-block:: php

    $data = ['hi', 'there', 'guy', '!'];
    $xf = t\replace(['hi' => 'You', '!' => '?']);
    $result = t\seq($data, $xf);
    assert($result == ['You', 'there', 'guy', '?']);

keep()
~~~~~~

``function keep(callable $f)``

Keeps ``$f`` items for which ``$f`` does not return null.

.. code-block:: php

    $result = t\seq(
        [0, false, null, true],
        t\keep(function ($value) { return $value; })
    );

    assert($result == [0, false, true]);

keep_indexed()
~~~~~~~~~~~~~~

``function keep_indexed(callable $f)``

Returns a sequence of the non-null results of ``$f($index, $input)``.

.. code-block:: php

    $result = t\seq(
        [0, false, null, true],
        t\keep_indexed(function ($index, $input) {
            echo $index . ':' . json_encode($input) . ', ';
            return $input;
        })
    );

    assert($result == [0, false, true]);

    // Will echo: 0:0, 1:false, 2:null, 3:true,

dedupe()
~~~~~~~~

``function dedupe()``

Removes duplicates that occur in order (keeping the first in a sequence of
duplicate values).

.. code-block:: php

    $result = t\seq(
        ['a', 'b', 'b', 'c', 'c', 'c', 'b'],
        t\dedupe()
    );

    assert($result == ['a', 'b', 'c', 'b']);

interpose()
~~~~~~~~~~~

``function interpose($separator)``

Adds a separator between each item in the sequence.

.. code-block:: php

    $result = t\seq(['a', 'b', 'c'], t\interpose('-'));
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

    // echo each value as it passes through the tap function.
    $tap = t\tap(function ($r, $x) { echo $x . ', '; });

    t\seq(
        ['a', 'b', 'c'],
        t\comp(
            $tap,
            t\map(function ($v) { return strtoupper($v); }),
            $tap
        )
    );

    // Prints: a, A, b, B, c, C,

compact()
~~~~~~~~~

``function compact()``

Trim out all falsey values.

.. code-block:: php

    $result = t\seq(['a', true, false, 'b', 0], t\compact());
    assert($result = ['a', true, 'b']);

Utility Functions
-----------------

identity()
~~~~~~~~~~

``function indentity($value)``

Returns the provided value. This is useful for writing reducing function arrays
that do not need to modify an 'init' or 'result' function. In these cases, you
can simply use the string ``'Transducers\identity'`` as the 'init' or 'result'
function to continue to proxy to further reducers.

indexed_iter()
~~~~~~~~~~~~~~

``function indexed_iter($iterable)``

Converts an iterable into an indexed array iterator where each value yielded
is an array containing the key followed by the value.

.. code-block:: php

    $data = ['a' => 1, 'b' => 2];
    assert(t\indexed_iter($data) == [['a', 1], ['b', 2]];

This can be combined with the ``assoc_reducer()`` to generate associative
arrays.

.. code-block:: php

    $result = t\transduce(
        t\map(function ($v) { return [$v[0], $v[1] + 1]; },
        t\assoc(),
        t\indexed_iter(['a' => 1, 'b' => 2])
    );

    assert($result == ['a' => 2, 'b' => 3]);

You should really just use the ``t\to_assoc()`` function if you know you're
reducing an associative array.

.. code-block:: php

    $result = t\to_assoc(
        ['a' => 1, 'b' => 2],
        t\map(function ($v) { return [$v[0], $v[1] + 1]; }
    );

    assert($result == ['a' => 2, 'b' => 3]);

stream_iter()
~~~~~~~~~~~~~

``function stream_iter($stream, $size = 1)``

Creates an iterator that reads from a stream using the given ``$size`` argument.

.. code-block:: php

    $s = fopen('php://temp', 'w+');
    fwrite($s, 'foo');
    rewind($s);

    // outputs: foo
    foreach (t\stream_iter($s) as $char) {
        echo $char;
    }

    rewind($s);

    // outputs: fo-o
    foreach (t\stream_iter($s, 2) as $char) {
        echo $char . '-';
    }

vec()
~~~~~

Converts an input value into something this is iterable (e.g., an array or
``\Iterator``). This function accepts arrays, ``\Iterators``, PHP streams, and
strings. Arrays pass through unchanged. Associative arrays are returned as
iterators that yield arrays where each value is an array that contains the key
of the array in the first element and the value of the array in the second
element. Iterators are returned as-is. Strings are split by character using
``str_split()``. PHP streams are converted into iterators that yield a single
byte at a time.

<?php
namespace Transducers;

/**
 * Lazily applies the transducer $xf to the $input iterator.
 *
 * @param mixed    $iterable Iterable input to transform.
 * @param callable $xf       Transducer to apply.
 *
 * @return \Iterator Returns an iterator that lazily applies transformations.
 */
function to_iter($iterable, callable $xf)
{
    $items = [];
    $reducer = $xf([
        'init'   => 'Transducers\identity',
        'result' => 'Transducers\identity',
        'step'   => function ($result, $input) use (&$items) {
            $items[] = $input;
            return $result;
        }
    ]);

    $result = $reducer['init']();

    foreach ($iterable as $input) {
        $result = $reducer['step']($result, $input);
        // Yield each queued value from the step function.
        while ($items) {
            yield array_shift($items);
        }
        // Break early if a Reduced is found.
        if ($result instanceof Reduced) {
            break;
        }
    }

    // Allow reducers to step on the final result.
    $reducer['result']($result);

    while ($items) {
        yield array_shift($items);
    }
}

/**
 * Converts a value to an array using a transducer function.
 *
 * @param mixed    $coll Value to convert.
 * @param callable $xf   Transducer to apply.
 *
 * @return array
 * @throws \InvalidArgumentException
 */
function to_array($coll, callable $xf)
{
    return transduce($xf, array_reducer(), vec($coll), []);
}

/**
 * Converts a value to an associative array using a transducer function.
 *
 * Do not provide an indexed array (i.e., [[0, 1], [1, 1], [2, 2]]) as this
 * function will do that for you. Note that values yielded through each
 * transducer will be an array where element 0 is the associative array key and
 * element 1 is the associative array value.
 *
 * @param mixed    $coll Value to convert.
 * @param callable $xf   Transducer to apply.
 *
 * @return array Returns an associative array.
 * @throws \InvalidArgumentException
 */
function to_assoc($coll, callable $xf)
{
    return transduce($xf, assoc_reducer(), indexed_iter($coll), []);
}

/**
 * Reduces a value to a string by concatenating each step value to a string.
 *
 * @param mixed    $coll Value to convert.
 * @param callable $xf   Transducer to apply.
 *
 * @return string
 * @throws \InvalidArgumentException
 */
function to_string($coll, callable $xf)
{
    return transduce($xf, string_reducer(), vec($coll), '');
}

/**
 * Transduces items from $coll into the given $target, in essence "pouring"
 * transformed data from one source into another data type.
 *
 * This function does not attempt to discern between arrays and associative
 * arrays. Any array or ArrayAccess object provided will be treated as an
 * indexed array. When a string is provided, each value will be concatenated to
 * the end of the string with no separator. When an fopen resource is provided,
 * data will be written to the end of the stream with no separator between
 * writes.
 *
 * @param array|\ArrayAccess|resource|string $target Where items are appended.
 * @param callable                           $xf     Transducer function.
 * @param mixed                              $coll   Sequence of data
 *
 * @return mixed
 * @throws \InvalidArgumentException
 */
function into($target, callable $xf, $coll)
{
    if (is_array($target) || $target instanceof \ArrayAccess) {
        return transduce($xf, array_reducer(), $coll, $target);
    } elseif (is_resource($target)) {
        return transduce($xf, stream_reducer(), $coll, $target);
    } elseif (is_string($target)) {
        return transduce($xf, string_reducer(), $coll, $target);
    }

    throw type_error('into', $coll);
}

/**
 * Returns the same data type passed in as $coll with $xf applied.
 *
 * This function will turn associative arrays into a stream of arrays that
 * contain the array key in the first element and values in the second element.
 *
 * @param array|\Iterator|resource $coll Data to transform.
 * @param callable                 $xf   Transducer to apply.
 * @return mixed
 * @throws \InvalidArgumentException
 */
function seq($coll, callable $xf)
{
    if (is_array($coll)) {
        reset($coll);
        return key($coll) === 0
            ? transduce($xf, array_reducer(), $coll, [])
            : transduce($xf, assoc_reducer(), indexed_iter($coll), []);
    } elseif ($coll instanceof \Iterator) {
        return to_iter($coll, $xf);
    } elseif (is_resource($coll)) {
        return transduce($xf, stream_reducer(), stream_iter($coll));
    } elseif (is_string($coll)) {
        return transduce($xf, string_reducer(), str_split($coll));
    }

    throw type_error('seq', $coll);
}

/**
 * Transform and reduce $coll by applying $xf($step)['step'] to each value.
 *
 * Returns the result of applying the transformed $xf to 'init' and the first
 * item in the $coll, then applying $xf to that result and the second item,
 * etc. If $coll contains no items, returns init and $f is not called.
 *
 * @param callable $xf   Transducer function.
 * @param array    $step Transformation array that contains an 'init', 'result',
 *                       and 'step' keys mapping to functions.
 * @param mixed    $coll The iterable collection to transform.
 * @param mixed    $init The first initialization value of the reduction.
 *
 * @return mixed
 */
function transduce(callable $xf, array $step, $coll, $init = null)
{
    if ($init === null) {
        $init = $step['init']();
    }

    $reducer = $xf($step);
    return $reducer['result'](reduce($reducer['step'], $coll, $init));
}

//-----------------------------------------------------------------------------
// Transducers
//-----------------------------------------------------------------------------

/**
 * Applies a map function $f to each value in a collection.
 *
 * @param callable $f Map function to apply.
 *
 * @return callable
 */
function map(callable $f)
{
    return function (array $xf) use ($f) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $f) {
                return $xf['step']($result, $f($input));
            }
        ];
    };
}

/**
 * Filters values that do not satisfy the predicate function $pred.
 *
 * @param callable $pred Function that accepts a value and returns true/false
 *
 * @return callable
 */
function filter(callable $pred)
{
    return function (array $xf) use ($pred) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($pred, $xf) {
                return $pred($input)
                    ? $xf['step']($result, $input)
                    : $result;
            },
        ];
    };
}

/**
 * Removes anything from a sequence that satisfied $pred
 *
 * @param callable $pred Function that accepts a value and returns true/false
 *
 * @return callable
 */
function remove(callable $pred)
{
    return function (array $xf) use ($pred) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($pred, $xf) {
                return !$pred($input)
                    ? $xf['step']($result, $input)
                    : $result;
            }
        ];
    };
}

/**
 * Concatenates items from nested lists.
 *
 * @return callable
 */
function cat()
{
    return function (array $xf) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf) {
                if (!is_iterable($input)) {
                    return $xf['step']($result, $input);
                }
                foreach ((array) $input as $value) {
                    $result = $xf['step']($result, $value);
                }
                return $result;
            }
        ];
    };
}

/**
 * Applies a map function to a collection and cats them into one less level of
 * nesting.
 *
 * @param callable $f Map function
 *
 * @return callable
 */
function mapcat(callable $f)
{
    return comp(map($f), cat());
}

/**
 * Takes any nested combination of sequential things and returns their contents
 * as a single, flat sequence.
 *
 * @return callable
 */
function flatten()
{
    return function (array $xf) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf) {
                if (!is_iterable($input)) {
                    return $xf['step']($result, $input);
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($input));
                foreach ($it as $value) {
                    $result = $xf['step']($result, $value);
                }
                return $result;
            }
        ];
    };
}

/**
 * Partitions the input sequence into partitions of the specified size.
 *
 * @param int $size Size to make each partition (except possibly the last chunk)
 *
 * @return callable
 */
function partition($size)
{
    return function (array $xf) use ($size) {
        $buffer = [];
        return [
            'init' => $xf['init'],
            'result' => function ($result) use (&$buffer, $xf) {
                return $buffer ? $xf['step']($result, $buffer) : $result;
            },
            'step' => function ($result, $input) use ($xf, &$buffer, $size) {
                $buffer[] = $input;
                if (count($buffer) == $size) {
                    $result = $xf['step']($result, $buffer);
                    $buffer = [];
                    return $result;
                }
                return $result;
            }
        ];
    };
}

/**
 * Split inputs into lists by starting a new list each time the predicate
 * passed in evaluates to a different condition (true/false) than what holds
 * for the present list.
 *
 * @param callable $pred Function that returns a new value to partition by.
 *
 * @return callable
 */
function partition_by(callable $pred)
{
    return function (array $xf) use ($pred) {
        $ctx = [];
        return [
            'init' => $xf['init'],
            'result' => function ($result) use (&$ctx, $xf) {
                // Add any pending elements.
                return empty($ctx['buffer'])
                    ? $result
                    : $xf['step']($result, $ctx['buffer']);
            },
            'step' => function ($result, $input) use ($xf, &$ctx, $pred) {
                $test = $pred($input);
                if (!$ctx) {
                    $ctx['last'] = $test;
                    $ctx['buffer'] = [$input];
                } elseif ($ctx['last'] !== $test) {
                    $ctx['last'] = $test;
                    if (!empty($ctx['buffer'])) {
                        $buffer = $ctx['buffer'];
                        $ctx['buffer'] = [$input];
                        return $xf['step']($result, $buffer);
                    }
                } else {
                    $ctx['buffer'][] = $input;
                }
                return $result;
            }
        ];
    };
}

/**
 * Takes $n number of values from a collection.
 *
 * @param int $n Number of value to take
 *
 * @return callable
 */
function take($n)
{
    return function (array $xf) use ($n) {
        $remaining = $n;
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($r, $input) use (&$remaining, $xf) {
                $r = $xf['step']($r, $input);
                return --$remaining > 0 ? $r : ensure_reduced($r);
            }
        ];
    };
}

/**
 * Takes from a collection while the predicate function $pred returns true.
 *
 * @param callable $pred Function that accepts a value and returns true/false
 *
 * @return callable
 */
function take_while(callable $pred)
{
    return function (array $xf) use ($pred) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($pred, $xf) {
                return $pred($input)
                    ? $xf['step']($result, $input)
                    : ensure_reduced($result);
            }
        ];
    };
}

/**
 * Takes every nth item from a sequence of values.
 *
 * @param int $nth The nth value to take
 *
 * @return callable
 */
function take_nth($nth)
{
    return function (array $xf) use ($nth) {
        $i = 0;
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, &$i, $nth) {
                return $i++ % $nth
                    ? $result
                    : $xf['step']($result, $input);
            }
        ];
    };
}

/**
 * Drops $n items from the beginning of the input sequence.
 *
 * @param int $n Number of items to drop
 *
 * @return callable
 */
function drop($n)
{
    return function (array $xf) use ($n) {
        $remaining = $n;
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, &$remaining) {
                return $remaining-- > 0
                    ? $result
                    : $xf['step']($result, $input);
            }
        ];
    };
}

/**
 * Drops values from a sequence so long as the predicate function $pred
 * returns true.
 *
 * @param callable $pred Predicate that accepts a value and returns true/false
 *
 * @return callable
 */
function drop_while(callable $pred)
{
    return function (array $xf) use ($pred) {
        $trigger = false;
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $pred, &$trigger) {
                if ($trigger) {
                    // No longer dropping.
                    return $xf['step']($result, $input);
                } elseif (!$pred($input)) {
                    // Predicate failed so stop dropping.
                    $trigger = true;
                    return $xf['step']($result, $input);
                }
                // Currently dropping
                return $result;
            }
        ];
    };
}

/**
 * Given a map of replacement pairs and a collection, returns a sequence where
 * any elements equal to a key in $smap are replaced with the corresponding
 * $smap value.
 *
 * @param array $smap Search term mapping to a replacement value.
 *
 * @return callable
 */
function replace(array $smap)
{
    return function (array $xf) use ($smap) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $smap) {
                return isset($smap[$input])
                    ? $xf['step']($result, $smap[$input])
                    : $xf['step']($result, $input);
            }
        ];
    };
}

/**
 * Keeps $f items for which $f does not return null.
 *
 * @param callable $f Function that accepts a value and returns null|mixed.
 *
 * @return callable
 */
function keep(callable $f)
{
    return function (array $xf) use ($f) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $f) {
                $value = $f($input);
                return $value !== null
                    ? $xf['step']($result, $value)
                    : $result;
            }
        ];
    };
}

/**
 * Returns a sequence of the non-null results of $f($index, $input).
 *
 * @param callable $f Function that accepts an index and an item and returns
 *                    a value. Anything other than null is kept.
 * @return callable
 */
function keep_indexed(callable $f)
{
    return function (array $xf) use ($f) {
        $idx = 0;
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $f, &$idx) {
                $value = $f($idx++, $input);
                return $value !== null
                    ? $xf['step']($result, $value)
                    : $result;
            }
        ];
    };
}

/**
 * Removes duplicates that occur in order (keeping the first in a sequence of
 * duplicate values).
 *
 * @return callable
 */
function dedupe()
{
    return function (array $xf) {
        $outer = [];
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, &$outer) {
                if (!array_key_exists('prev', $outer)
                    || $outer['prev'] !== $input
                ) {
                    $outer['prev'] = $input;
                    return $xf['step']($result, $input);
                }
                return $result;
            }
        ];
    };
}

/**
 * Adds a separator between each item in the sequence.
 *
 * @param mixed $separator Separator to interpose
 *
 * @return callable
 */
function interpose($separator)
{
    return function (array $xf) use ($separator) {
        $triggered = 0;
        return [
            'init' => $xf['init'],
            'result' => $xf['result'],
            'step' => function ($result, $input)
                use ($xf, $separator, &$triggered) {
                if (!$triggered) {
                    $triggered = true;
                    return $xf['step']($result, $input);
                }
                return $xf['step']($xf['step']($result, $separator), $input);
            }
        ];
    };
}

/**
 * Trim out all falsey values.
 *
 * @return callable
 */
function compact()
{
    return function (array $xf) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf) {
                return $input ? $xf['step']($result, $input) : $result;
            }
        ];
    };
}

/**
 * Invokes interceptor with each result and item, and then steps through
 * unchanged.
 *
 * The primary purpose of this method is to "tap into" a method chain, in order
 * to perform operations on intermediate results within the chain. Executes
 * interceptor with current result and item.
 *
 * @param callable $interceptor
 *
 * @return callable
 */
function tap(callable $interceptor)
{
    return function (array $xf) use ($interceptor) {
        return [
            'init'   => $xf['init'],
            'result' => $xf['result'],
            'step'   => function ($result, $input) use ($xf, $interceptor) {
                $interceptor($result, $input);
                return $xf['step']($result, $input);
            }
        ];
    };
}

//-----------------------------------------------------------------------------
// Reducing function arrays
//-----------------------------------------------------------------------------

/**
 * Creates a reducing function array that appends values to an array or object
 * that implements {@see ArrayAccess}.
 *
 * @return array Returns a reducing function array.
 */
function array_reducer()
{
    return [
        'init'   => function () { return []; },
        'result' => 'Transducers\identity',
        'step'   => function ($result, $input) {
            $result[] = $input;
            return $result;
        }
    ];
}

/**
 * Creates a hash map reducing function array that merges values into an
 * associative array.
 *
 * This reducer assumes that the provided value is an array where the key is
 * in the first index and the value is in the second index.
 *
 * @return array Returns a reducing function array.
 */
function assoc_reducer()
{
    return [
        'init'   => function () { return []; },
        'result' => 'Transducers\identity',
        'step'   => function ($result, $input) {
            $result[$input[0]] = $input[1];
            return $result;
        }
    ];
}

/**
 * Creates a stream reducing function array for PHP stream resources.
 *
 * @return array Returns a reducing function array.
 */
function stream_reducer()
{
    return [
        'init'   => function () { return fopen('php://temp', 'w+'); },
        'result' => 'Transducers\identity',
        'step' => function ($result, $input) {
            fwrite($result, $input);
            return $result;
        }
    ];
}

/**
 * Creates a string reducing function array that concatenates values into a
 * string.
 *
 * @param string $joiner Optional string to concatenate between each value.
 *
 * @return array Returns a reducing function array.
 */
function string_reducer($joiner = '')
{
    return [
        'init'   => function () { return ''; },
        'result' => 'Transducers\identity',
        'step'   => function ($r, $x) use ($joiner) {
            return $r . $joiner . $x;
        }
    ];
}

/**
 * Convenience function for creating a reducing function array.
 *
 * @param callable $step   Step function that accepts $accum, $input and
 *                         returns a new reduced value.
 * @param callable $init   Optional init function invoked with no argument to
 *                         initialize the reducing function.
 * @param callable $result Optional result function invoked with a single
 *                         argument that is expected to return a result.
 *
 * @return array Returns a reducing function array.
 */
function create_reducer(callable $step, callable $init = null, callable $result = null)
{
    return [
        'init'   => $init ?: function () {},
        'result' => $result ?: 'Transducers\identity',
        'step'   => $step
    ];
}

//-----------------------------------------------------------------------------
// Utility functions
//-----------------------------------------------------------------------------

/**
 * Composes the provided variadic function arguments into a single function.
 *
 *     comp($f, $g) // returns $f($g(x))
 *
 * @return callable
 */
function comp()
{
    $fns = func_get_args();
    $total = count($fns) - 1;
    return function ($value) use ($fns, $total) {
        for ($i = $total; $i > -1; $i--) {
            $value = $fns[$i]($value);
        }
        return $value;
    };
}

/**
 * Reduces the given iterable using the provided reduce function $fn. The
 * reduction is short-circuited if $fn returns an instance of Reduced.
 *
 * @param callable $fn    Reduce function.
 * @param mixed    $coll  Iterable data to transform.
 * @param mixed    $accum Initial accumulated value.
 * @return mixed Returns the reduced value
 */
function reduce(callable $fn, $coll, $accum = null)
{
    foreach ($coll as $input) {
        $accum = $fn($accum, $input);
        if ($accum instanceof Reduced) {
            return $accum->value;
        }
    }

    return $accum;
}

/**
 * Converts an iterable into a sequence of data.
 *
 * When provided an indexed array, the array is returned as-is. When provided
 * an associative array, an iterator is returned where each value is an array
 * containing the [key, value]. When a stream is provided, an iterator is
 * returned that yields bytes from the stream. When an iterator is provided,
 * it is returned as-is. To force an iterator to be an indexed iterator, you
 * must use the indexed_iter() function.
 *
 * @param array|\Iterator|resource $iterable Data to convert to a sequence.
 *
 * @return array|\Iterator
 * @throws \InvalidArgumentException
 */
function vec($iterable)
{
    if (is_array($iterable)) {
        reset($iterable);
        return key($iterable) === 0 ? $iterable : indexed_iter($iterable);
    } elseif ($iterable instanceof \Iterator) {
        return $iterable;
    } elseif (is_resource($iterable)) {
        return stream_iter($iterable);
    } elseif (is_string($iterable)) {
        return str_split($iterable);
    }

    throw type_error('vec', $iterable);
}

/**
 * Returns the provided Reduced or wraps the value in a Reduced.
 *
 * @param mixed|Reduced $r Value to ensure is reduced.
 *
 * @return Reduced
 */
function ensure_reduced($r)
{
    return $r instanceof Reduced ? $r : new Reduced($r);
}

/**
 * Returns the provided value.
 *
 * @param mixed $value Value to return
 *
 * @return mixed
 */
function identity($value = null)
{
    return $value;
}

/**
 * Converts an iterable into an indexed array iterator where each value yielded
 * is an array containing the key followed by the value.
 *
 * @param mixed $iterable Value to convert to an indexed iterator
 *
 * @return \Iterator
 */
function indexed_iter($iterable)
{
    foreach ($iterable as $key => $value) {
        yield [$key, $value];
    }
}

/**
 * Creates an iterator that reads from a stream.
 *
 * @param resource $stream fopen() resource.
 * @param int      $size   Number of bytes to read for each read. Defaults to 1.
 *
 * @return \Iterator
 */
function stream_iter($stream, $size = 1)
{
    while (!feof($stream)) {
        yield fread($stream, $size);
    }
}

/**
 * Returns true if the provided $coll is something that can be iterated in a
 * foreach loop.
 *
 * This function treats arrays, instances of \Traversable, and stdClass as
 * iterable.
 *
 * @param mixed $coll
 *
 * @return bool
 */
function is_iterable($coll)
{
    return is_array($coll)
        || $coll instanceof \Traversable
        || $coll instanceof \stdClass;
}

/**
 * @param string $name Name of the function that was called.
 * @param mixed  $coll Data that was provided.
 *
 * @return \InvalidArgumentException
 */
function type_error($name, $coll)
{
    if (is_object($coll)) {
        $description = get_class($coll);
    } else {
        ob_start();
        var_dump($coll);
        $description = ob_end_clean();
    }
    return new \InvalidArgumentException("Do not know how to $name collection: "
        . $description);
}

//-----------------------------------------------------------------------------
// Utility classes
//-----------------------------------------------------------------------------

class Reduced
{
    public $value;

    public function __construct($value)
    {
        $this->value = $value;
    }
}

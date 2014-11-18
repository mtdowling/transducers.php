<?php
namespace Transducers;

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
 * Creates a transformer that appends values to an array.
 *
 * @return array Returns a transformer array.
 */
function append()
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
 * Creates a transducer that writes to a stream resource.
 *
 * @return array Returns a transformer array.
 */
function stream()
{
    return [
        'init' => function () {
            return fopen('php://temp', 'w+');
        },
        'result' => function ($result) {
            rewind($result);
            return $result;
        },
        'step' => function ($result, $input) {
            fwrite($result, $input);
            return $result;
        }
    ];
}

/**
 * Transduces items from $coll into the given $target, in essence "pouring"
 * transformed data from one source into another data type.
 *
 * @param array|\ArrayAccess|resource    $target Where items are appended.
 * @param callable                       $xf     Transducer function.
 * @param mixed                          $coll   Sequence of data
 *
 * @return mixed
 * @throws \InvalidArgumentException
 */
function into($target, callable $xf, $coll)
{
    if (is_array($target) || $target instanceof \ArrayAccess) {
        return transduce($xf, append(), $coll, $target);
    } elseif (is_resource($target)) {
        return transduce($xf, stream(), $coll, $target);
    }

    throw _type_error('into', $coll);
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
 * Lazily applies the transducer $xf to the $input iterator.
 *
 * @param mixed    $coll Iterable input to transform.
 * @param callable $xf   Transducer to apply.
 *
 * @return \Iterator Returns an iterator that lazily applies transformations.
 */
function xfiter($coll, callable $xf)
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

    foreach ($coll as $input) {
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
 * Returns the same data type passed in as $coll with $xf applied.
 *
 * @param array|\Iterator|resource $coll Data to transform.
 * @param callable                 $xf   Transducer to apply.
 * @return mixed
 * @throws \InvalidArgumentException
 */
function seq($coll, callable $xf)
{
    if (is_array($coll)) {
        return transduce($xf, append(), $coll, []);
    } elseif ($coll instanceof \Iterator) {
        return xfiter($coll, $xf);
    } elseif (is_resource($coll)) {
        return transduce($xf, stream(), stream_iter($coll));
    }

    throw _type_error('seq', $coll);
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
    switch (gettype($iterable)) {
        case 'array':
            return !$iterable || array_keys($iterable)[0] === 0
                ? $iterable
                : indexed_iter($iterable);
        case 'string': return str_split($iterable);
        case 'resource': return stream_iter($iterable);
        case 'object':
            if ($iterable instanceof \Iterator) {
                return $iterable;
            }
    }

    throw _type_error('vec', $iterable);
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
 * Convert a value to an array.
 *
 * @param mixed $iterable Value to convert.
 *
 * @return array
 * @throws \InvalidArgumentException
 */
function to_array($iterable)
{
    if (is_array($iterable)) {
        return $iterable;
    } elseif ($iterable instanceof \Iterator) {
        return iterator_to_array($iterable);
    } elseif (is_string($iterable)) {
        return str_split($iterable);
    }

    throw _type_error('to_array', $iterable);
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
            'step'   => $n <= 0
                ? $xf['step']
                : function ($result, $input) use (&$remaining, $xf) {
                    $result = $xf['step']($result, $input);
                    return --$remaining ? $result : ensure_reduced($result);
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

/**
 * @param string $name Name of the function that was called.
 * @param mixed  $coll Data that was provided.
 *
 * @return \InvalidArgumentException
 */
function _type_error($name, $coll)
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

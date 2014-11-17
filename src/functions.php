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
function identity($value)
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
        'init'   => function () { return  []; },
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
        'result' => 'Transducers\identity',
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
 * Lazily applies the transducer $xf to the $input iterator.
 *
 * @param \Iterator $coll Input data to transform.
 * @param callable  $xf   Transducer to apply.
 *
 * @return \Iterator Returns an iterator that lazily applies transformations.
 */
function iter(\Iterator $coll, callable $xf)
{
    return new LazyTransformer($coll, $xf);
}

/**
 * Returns the same data type passed in as $coll with $xf applied.
 *
 * @param array|\Iterator|resource $coll Data to transform.
 * @param callable                 $xf   Transducer to apply.
 * @return LazyTransformer
 * @throws \InvalidArgumentException
 */
function seq($coll, callable $xf)
{
    if (is_array($coll)) {
        return transduce($xf, append(), $coll, []);
    } elseif ($coll instanceof \Iterator) {
        return new LazyTransformer($coll, $xf);
    } elseif (is_resource($coll)) {

    }

    throw _type_error('seq', $coll);
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
    $result = $reducer['result'](reduce($reducer['step'], $coll, $init));

    return $result instanceof Reduced ? $result->value : $result;
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
            'init' => $xf['init'],
            'result' => $xf['result'],
            'step' => function ($result, $input) use ($pred, $xf) {
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
            'init' => $xf['init'],
            'result' => $xf['result'],
            'step' => function ($result, $input) use ($pred, $xf) {
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
 * Chunks the input sequence into chunks of the specified size.
 *
 * @param int $size Size to make each chunk (except possibly the last chunk)
 *
 * @return callable
 */
function chunk($size)
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
                ? 'Transducers\identity'
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
                return $value === null
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
                return $value === null
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

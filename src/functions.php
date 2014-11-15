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
    $total = count($fns);

    return function ($value) use ($fns, $total) {
        for ($i = $total - 1; $i > 0; $i--) {
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
 * Creates a transducer function.
 *
 * @param callable $init     Initialization function.
 * @param callable $step     Step function (accepts $result, $input)
 * @param callable $complete Complete function that accepts a single value.
 *
 * @return callable
 */
function create(callable $init, callable $step, callable $complete)
{
    return function () use ($init, $step, $complete) {
        $args = func_get_args();
        switch (count($args)) {
            case 2: return $step($args[0], $args[1]);
            case 0: return $init();
            case 1: return $complete($args[0]);
            default: throw new \InvalidArgumentException('Invalid arity');
        }
    };
}

/**
 * Returns a value that can be used in a for-loop.
 *
 * @param mixed $coll Collection to iterate
 *
 * @return array|\Traversable|\Iterator
 */
function sequence($coll)
{
    if (is_array($coll) || $coll instanceof \Traversable) {
        return $coll;
    }

    throw new \InvalidArgumentException('Invalid collection');
}

/**
 * Creates a transducer that appends values to an array.
 *
 * @return callable
 */
function append()
{
    return create(
        function () {
            return [];
        },
        function ($r, $x) {
            $r[] = $x;
            return $r;
        },
        'Transducers\identity'
    );
}

/**
 * Creates a transducer that writes to a stream resource.
 *
 * @return callable
 */
function stream()
{
    return create(
        function () {
            return fopen('php://temp', 'w+');
        },
        function ($r, $x) {
            fwrite($r, $x);
            return $r;
        },
        'Transducers\identity'
    );
}

/**
 * Reduces the given iterable using the provided reduce function $fn. The
 * reduction is short-circuited if $fn returns an instance of Reduced.
 *
 * @param callable $fn          Reduce function that accepts ($result, $input)
 * @param mixed    $iterable    Array|Traversable|Iterator
 * @param null     $initializer Initial value to use with the reduce function.
 *
 * @return mixed Returns the reduced value
 */
function reduce(callable $fn, $iterable, $initializer = null)
{
    $result = $initializer;
    foreach (sequence($iterable) as $input) {
        $result = $fn($result, $input);
        if ($result instanceof Reduced) {
            return $result->value;
        }
    }

    return $result;
}

/**
 * Reduce with a transformation of f (xf).
 *
 * $f should be a function that has three different behaviors based on the
 * arity of calling $f. If an initial value is not provided, $f will be called
 * with no arguments to create the initial value. When called with two
 * arguments, $f should be a reducing step function that accepts the previous
 * value and next item and returns the next item. Finally, when the transform
 * is complete, $f is called once more with the completed value and must return
 * the completed value.
 *
 * Returns the result of applying the transformed $xf to init and the first
 * item in the $coll, then applying $xf to that result and the second item,
 * etc. If $coll contains no items, returns init and $f is not called.
 *
 * @param callable $xf   Transformation function
 * @param callable $step Reducing step function. This function has three
 *                       arities: 0 -> returns an initial value, 2 -> accepts a
 *                       result over result and new value and returns a new
 *                       value, 1 -> accepts the completed results and returns
 *                       a completed result.
 * @param mixed    $coll The iterable collection to transduce.
 * @param mixed    $init The first initialization value of the reduction.
 *
 * @return mixed
 */
function transduce(callable $xf, callable $step, $coll, $init = null)
{
    if ($init === null) {
        $init = $step();
    }

    $reducer = $xf($step);
    $result = $reducer(reduce($reducer, $coll, $init));

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
    return function (callable $step) use ($f) {
        return create(
            $step,
            function ($result, $input) use ($step, $f) {
                return $step($result, $f($input));
            },
            $step
        );
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
    return function (callable $step) use ($pred) {
        return create(
            $step,
            function ($result, $input) use ($pred, $step) {
                return $pred($input) ? $step($result, $input) : $result;
            },
            $step
        );
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
    return function (callable $step) use ($pred) {
        return create(
            $step,
            function ($result, $input) use ($pred, $step) {
                return !$pred($input) ? $step($result, $input) : $result;
            },
            $step
        );
    };
}

/**
 * Concatenates items from nested lists.
 *
 * @return callable
 */
function cat()
{
    return function (callable $step) {
        return create(
            $step,
            function ($result, $input) use ($step) {
                return array_reduce((array) $input, $step, $result);
            },
            $step
        );
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
 * Takes $n number of values from a collection.
 *
 * @param int $n Number of value to take
 *
 * @return callable
 */
function take($n)
{
    return function (callable $step) use ($n) {
        $remaining = $n;
        return create(
            $step,
            $n <= 0
                ? 'Transducers\identity'
                : function ($result, $input) use (&$remaining, $step) {
                    $result = $step($result, $input);
                    return --$remaining ? $result : ensure_reduced($result);
                },
            $step
        );
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
    return function (callable $step) use ($pred) {
        return create(
            $step,
            function ($result, $input) use ($pred, $step) {
                return $pred($input)
                    ? $step($result, $input)
                    : new Reduced($result);
            },
            $step
        );
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
    return function (callable $step) use ($nth) {
        $i = 0;
        return create(
            $step,
            function ($result, $input) use ($step, &$i, $nth) {
                return $i++ % $nth ? $result : $step($result, $input);
            },
            $step
        );
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
    return function (callable $step) use ($n) {
        $remaining = $n;
        return create(
            $step,
            function ($result, $input) use ($step, &$remaining) {
                return $remaining-- > 0 ? $result : $step($result, $input);
            },
            $step
        );
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
    return function (callable $step) use ($pred) {
        $trigger = false;
        return create(
            $step,
            function ($result, $input) use ($step, $pred, &$trigger) {
                if ($trigger) {
                    // No longer dropping.
                    return $step($result, $input);
                } elseif (!$pred($input)) {
                    // Predicate failed so stop dropping.
                    $trigger = true;
                    return $step($result, $input);
                }
                // Currently dropping
                return $result;
            },
            $step
        );
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
function replace($smap)
{
    return function ($step) use ($smap) {
        return create(
            $step,
            function ($result, $input) use ($step, $smap) {
                return isset($smap[$input])
                    ? $step($result, $smap[$input])
                    : $step($result, $input);
            },
            $step
        );
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
    return function ($step) use ($f) {
        return create(
            $step,
            function ($result, $input) use ($step, $f) {
                $result = $f($input);
                return $result === null ? $step($result, $result) : $result;
            },
            $step
        );
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
    return function ($step) use ($f) {
        $idx = 0;
        return create(
            $step,
            function ($result, $input) use ($step, $f, &$idx) {
                $result = $f($idx++, $input);
                return $result === null ? $step($result, $result) : $result;
            },
            $step
        );
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
    return function (callable $step) {
        $outer = [];
        return create(
            $step,
            function ($result, $input) use ($step, &$outer) {
                if (!array_key_exists('prev', $outer)
                    || $outer['prev'] !== $input
                ) {
                    $outer['prev'] = $input;
                    return $step($result, $input);
                }
                return $result;
            },
            $step
        );
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
    return function (callable $step) use ($separator) {
        $triggered = 0;
        return create(
            $step,
            function ($result, $input) use ($step, $separator, &$triggered) {
                if (!$triggered) {
                    $triggered = true;
                    return $step($result, $input);
                } else {
                    return $step($step($result, $separator), $input);
                }
            },
            $step
        );
    };
}

/**
 * Transduces items from $coll into the given $target.
 *
 * @param mixed    $target Where items are appended.
 * @param callable $xf     Transducer function.
 * @param mixed    $coll   Sequence of data
 *
 * @return mixed
 */
function into($target, callable $xf, $coll)
{
    if (is_array($target) || $target instanceof \ArrayAccess) {
        return transduce($xf, append(), $coll, $target);
    } elseif (is_resource($target)) {
        return transduce($xf, stream(), $coll, $target);
    }

    throw new \InvalidArgumentException('Unknown target provided');
}

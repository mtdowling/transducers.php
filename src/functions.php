<?php
namespace Transducers;

/**
 * Composes functions using a reduce.
 *
 *     comp($f, $g) // returns $f($g(x))
 *
 * @param callable[] $fns Functions to compose.
 *
 * @return callable
 */
function comp(array $fns)
{
    if (!$fns) {
        throw new \InvalidArgumentException('Must provide an array of functions');
    }

    return array_reduce($fns, function (callable $f, callable $g) {
        return function ($x) use ($f, $g) {
            return $f($g($x));
        };
    }, array_shift($fns));
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
 * @param callable $step     Step function (accepts $carry, $item)
 * @param callable $complete Complete function that accepts a single value.
 *
 * @return callable
 */
function create(callable $init, callable $step, callable $complete)
{
    return function () use ($init, $step, $complete) {
        $args = func_get_args();
        switch (count($args)) {
            case 0: return $init();
            case 2: return $step($args[0], $args[1]);
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
    if (is_array($coll)) {
        return new \ArrayIterator($coll);
    } elseif ($coll instanceof \Traversable) {
        return $coll;
    }

    throw new \InvalidArgumentException('Invalid collection');
}

/**
 * Creates a transducer function that appends to an array.
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
 * Reduces the given iterable using the provided reduce function $fn. The
 * reduction is short-circuited if $fn returns an instance of Reduced.
 *
 * @param callable $fn          Reduce function that accepts ($carry, $item)
 * @param mixed    $iterable    Array|Traversable|Iterator
 * @param null     $initializer Initial value to use with the reduce function.
 *
 * @return mixed Returns the reduced value
 */
function reduce(callable $fn, $iterable, $initializer = null)
{
    $carry = $initializer;
    foreach (sequence($iterable) as $item) {
        $carry = $fn($carry, $item);
        if ($carry instanceof Reduced) {
            return $carry->value;
        }
    }

    return $carry;
}

/**
 * Returns the result of calling transduce on the reducing function.
 *
 * You can compose transducers using the comp() function.
 *
 * @param callable $xform Transformation function
 * @param callable $f     Reduction function
 * @param mixed    $coll  The iterable collection to transduce.
 * @param mixed    $init  The first initialization value of the reduction.
 *
 * @return mixed
 */
function transduce(callable $xform, callable $f, $coll, $init = null)
{
    if ($init === null) {
        $result = transduce($xform, $f, $coll, $f());
    } else {
        $reducer = $xform($f);
        $result = $reducer(reduce($reducer, $coll, $init));
    }

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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($step, $f) {
                return $step($carry, $f($item));
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($pred, $step) {
                return $pred($item) ? $step($carry, $item) : $item;
            },
            'Transducers\identity'
        );
    };
}

/**
 * Concatenates items from nested lists.
 *
 * @param callable $step Step function to apply to each cat
 *
 * @return callable
 */
function cat(callable $step)
{
    return create(
        function () use ($step) {
            return $step();
        },
        function ($carry, $item) use ($step) {
            return array_reduce((array) $item, $step, $carry);
        },
        function ($completed) use ($step) {
            return $step($completed);
        }
    );
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
    return comp([map($f), 'Transducers\cat']);
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
            function () use ($step) {
                return $step();
            },
            $n <= 0
                ? 'Transducers\identity'
                : function ($carry, $item) use (&$remaining, $step) {
                $carry = $step($carry, $item);
                return --$remaining ? $carry : ensure_reduced($carry);
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($pred, $step) {
                return $pred($item)
                    ? $step($carry, $item)
                    : new Reduced($carry);
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($step, &$i, $nth) {
                return $i++ % $nth ? $carry : $step($carry, $item);
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($step, &$remaining) {
                return $remaining-- > 0 ? $carry : $step($carry, $item);
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
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
            function () use ($step) {
                return $step();
            },
            function ($carry, $item) use ($step, $pred, &$trigger) {
                if ($trigger) {
                    // No longer dropping.
                    return $step($carry, $item);
                } elseif (!$pred($item)) {
                    // Predicate failed so stop dropping.
                    $trigger = true;
                    return $step($carry, $item);
                }
                // Currently dropping
                return $carry;
            },
            function ($complete) use ($step) {
                return $step($complete);
            }
        );
    };
}

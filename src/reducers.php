<?php
namespace transducers;

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
 * Creates a reducing function array that uses the provided infix operator to
 * reduce the collection (i.e., $result <operator> $input).
 *
 * Supports: '.', '+', '-', '*', and '/' operators.
 *
 * @param string $operator Infix operator to use.
 *
 * @return array Returns a reducing function array.
 */
function operator_reducer($operator)
{
    static $reducers;
    if (!$reducers) {
        $reducers = [
            '.'  => function ($r, $x) { return $r . $x; },
            '+'  => function ($r, $x) { return $r + $x; },
            '-'  => function ($r, $x) { return $r - $x; },
            '*'  => function ($r, $x) { return $r * $x; },
            '/'  => function ($r, $x) { return $r / $x; }
        ];
    }

    if (!isset($reducers[$operator])) {
        throw new \InvalidArgumentException("A reducer is not defined for {$operator}");
    }

    return [
        'init'   => 'Transducers\identity',
        'result' => 'Transducers\identity',
        'step'   => $reducers[$operator]
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

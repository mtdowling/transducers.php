===============
transducers.php
===============

Transducers in PHP.

.. code-block:: php

    require 'vendor/autoload.php';

    use Transducers as T;

    $result = T\transduce(
        T\comp([
            // Flatten the sequence
            T\mapcat('Transducers\identity'),
            // Multiply each value * 2
            T\map(function ($value) {
                return $value * 2;
            }),
            // Stop when a value is >= 20
            T\take_while(function ($value) {
                return $value < 20;
            }),
            // Remove the first two entries
            T\drop(2)
        ]),
        // Append each element to an array.
        T\append(),
        // Data to filter
        [[1, 2, 3], [4, 5], [6], [], [7], [8, 9, 10, 11]]
    );

    var_export($result);

<?php
namespace Transducers;

/**
 * Boxes a reduced value.
 */
class Reduced
{
    public $value;

    /**
     * @param mixed $value The reduced value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }
}

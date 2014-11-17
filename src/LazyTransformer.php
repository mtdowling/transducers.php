<?php
namespace Transducers;

/**
 * Lazy applies a transformation over a sequence of data.
 */
class LazyTransformer implements \Iterator
{
    private $stepper;
    private $idx = 0;
    private $item;
    private $items;
    private $prev;
    private $invalid = false;

    /**
     * @param \Iterator $input Data to transform
     * @param callable  $xf    Transducer to apply.
     */
    public function __construct(\Iterator $input, callable $xf)
    {
        $this->input = $input;
        $this->stepper = $xf([
            'init'   => 'Transducers\identity',
            'result' => 'Transducers\identity',
            'step'   => function ($result, $input) {
                $this->items[] = $input;
                return $result;
            }
        ]);
    }

    public function rewind()
    {
        $this->items = [];
        $this->next();
    }

    public function current()
    {
        return $this->item;
    }

    public function next()
    {
        if ($this->items) {
            $this->idx++;
            $this->item = array_shift($this->items);
        } elseif ($this->prev instanceof Reduced || !$this->input->valid()) {
            $this->invalid = true;
        } else {
            $this->prev = $this->stepper['step']($this->prev, $this->input->current());
            $this->input->next();
            $this->next();
        }
    }

    public function valid()
    {
        return !$this->invalid;
    }

    public function key()
    {
        return $this->idx;
    }
}

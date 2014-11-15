<?php
namespace Transducers;

/**
 * Lazy applies a transformation over a sequence of data.
 */
class LazyTransformer implements \Iterator
{
    /** @var callable */
    private $stepper;
    private $idx = 0;
    private $item;
    private $items;
    private $prev;

    /**
     * @param \Iterator $input Data to transform
     * @param callable  $xf    Transducer to apply.
     */
    public function __construct(\Iterator $input, callable $xf)
    {
        $this->input = $input;
        $this->stepper = $xf(create(
            'Transducers\identity',
            function ($result, $input) {
                $this->items[] = $input;
                return $result;
            },
            'Transducers\identity'
        ));
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
        } elseif ($this->input->valid()) {
            $stepper = $this->stepper;
            $this->prev = $stepper($this->prev, $this->input->current());
            $this->input->next();
            $this->next();
        }
    }

    public function valid()
    {
        return (!$this->prev instanceof Reduced)
            && ($this->items || $this->input->valid());
    }

    public function key()
    {
        return $this->idx;
    }
}

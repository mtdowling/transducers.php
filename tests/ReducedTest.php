<?php
namespace Transducers\Tests;

use Transducers\Reduced;

class ReducedTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructor()
    {
        $r = new Reduced('foo');
        $this->assertEquals('foo', $r->value);
    }
}

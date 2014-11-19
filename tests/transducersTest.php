<?php
namespace Transducers\Tests;

use Transducers as t;

class functionsTest extends \PHPUnit_Framework_TestCase
{
    public function testComposesFunctions()
    {
        $a = function ($x) {
            $this->assertEquals(3, $x);
            return $x + 1;
        };

        $b = function ($x) {
            $this->assertEquals(1, $x);
            return $x + 2;
        };

        $c = t\comp($a, $b);
        $this->assertEquals(4, $c(1));
    }

    public function testEnsuresReduced()
    {
        $r = t\ensure_reduced(1);
        $this->assertEquals(1, $r->value);
        $r = t\ensure_reduced($r);
        $this->assertEquals(1, $r->value);
    }

    public function testReturnsIdentity()
    {
        $this->assertEquals(1, t\identity(1));
    }

    public function testReturnsAppendXform()
    {
        $xf = t\array_reducer();
        $this->assertEquals([], $xf['init']());
        $this->assertSame([10, 1], $xf['step']([10], 1));
        $this->assertSame([10], $xf['result']([10]));
    }

    public function testReturnsStreamXform()
    {
        $xf = t\stream_reducer();
        $res = $xf['init']();
        $this->assertInternalType('resource', $res);
        $this->assertSame($res, $xf['step']($res, 'a'));
        fseek($res, 0);
        $this->assertEquals('a', stream_get_contents($res));
        $this->assertSame($res, $xf['result']($res));
        fclose($res);
    }

    public function testTransformStreamWithSeq()
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, '012304');
        rewind($stream);
        $result = t\seq($stream, t\compact());
        rewind($result);
        $this->assertEquals('1234', stream_get_contents($result));
    }

    public function testCompactTrimsFalseyValues()
    {
        $data = [0, false, true, 10, ' ', 'a'];
        $result = t\into([], t\compact(), $data);
        $this->assertEquals([true, 10, ' ', 'a'], $result);
    }

    public function testTapsIntoReduce()
    {
        $data = ['a', 'b', 'c'];
        $res = [];
        $result = t\into([], t\tap(function ($r, $x) use (&$res) {
            $res[] = $x;
        }), $data);
        $this->assertSame($res, $result);
    }

    public function testInterposes()
    {
        $data = ['a', 'b', 'c'];
        $result = t\into([], t\interpose('-'), $data);
        $this->assertEquals(['a', '-', 'b', '-', 'c'], $result);
    }

    public function testRemovesDuplicates()
    {
        $data = ['a', 'b', 'b', 'c', 'c', 'c', 'b'];
        $result = t\into([], t\dedupe(), $data);
        $this->assertEquals(['a', 'b', 'c', 'b'], $result);
    }

    public function testMaps()
    {
        $data = ['a', 'b', 'c'];
        $xf = t\map(function ($value) { return strtoupper($value); });
        $result = t\into([], $xf, $data);
        $this->assertEquals(['A', 'B', 'C'], $result);
    }

    public function testFilters()
    {
        $data = [1, 2, 3, 4];
        $odd = function ($value) { return $value % 2; };
        $result = t\into([], t\filter($odd), $data);
        $this->assertEquals([1, 3], $result);
    }

    public function testRemoves()
    {
        $data = [1, 2, 3, 4];
        $odd = function ($value) { return $value % 2; };
        $result = t\into([], t\remove($odd), $data);
        $this->assertEquals([2, 4], $result);
    }

    public function testCats()
    {
        $data = [[1, 2], 3, [], [4, 5]];
        $result = t\into([], t\cat(), $data);
        $this->assertEquals($result, [1, 2, 3, 4, 5]);
    }

    public function testMapCats()
    {
        $data = [[1, 2], [3], [], [4, 5]];
        $xf = t\mapcat(function ($value) { return array_sum($value); });
        $result = t\into([], $xf, $data);
        $this->assertEquals($result, [3, 3, 0, 9]);
    }

    public function testFlattensIterables()
    {
        $data = [[1, 2], [3, [4, 5, new \ArrayObject([6, 7])]], [], [8, 9]];
        $result = t\into([], t\flatten(), $data);
        $this->assertEquals($result, [1, 2, 3, 4, 5, 6, 7, 8, 9]);
    }

    public function testPartitions()
    {
        $data = [1, 2, 3, 4, 5];
        $xf = t\partition(2);
        $result = t\into([], $xf, $data);
        $this->assertEquals($result, [[1, 2], [3, 4], [5]]);
    }

    public function testPartitionsByPredicate()
    {
        $data = [['a', 1], ['a', 2], [2, 3], ['c', 4]];
        $xf = t\partition_by(function ($v) { return is_string($v[0]); });
        $result = t\into([], $xf, $data);
        $this->assertEquals(
            $result,
            [[['a', 1], ['a', 2]], [[2, 3]], [['c', 4]]]
        );
    }

    public function testTakes()
    {
        $data = [1, 2, 3, 4, 5];
        $result = t\seq($data, t\take(2));
        $this->assertEquals($result, [1, 2]);
    }

    public function testDrops()
    {
        $data = [1, 2, 3, 4, 5];
        $result = t\seq($data, t\drop(2));
        $this->assertEquals($result, [3, 4, 5]);
    }

    public function testTakesNth()
    {
        $data = [1, 2, 3, 4, 5, 6];
        $result = t\seq($data, t\take_nth(2));
        $this->assertEquals($result, [1, 3, 5]);
    }

    public function testTakesWhile()
    {
        $data = [1, 2, 3, 4, 5];
        $xf = t\take_while(function ($value) { return $value < 4; });
        $result = t\seq($data, $xf);
        $this->assertEquals($result, [1, 2, 3]);
    }

    public function testDropsWhile()
    {
        $data = [1, 2, 3, 4, 5];
        $xf = t\drop_while(function ($value) { return $value < 3; });
        $result = t\seq($data, $xf);
        $this->assertEquals($result, [3, 4, 5]);
    }

    public function testReplaces()
    {
        $data = ['hi', 'there', 'guy', '!'];
        $xf = t\replace(['hi' => 'You', '!' => '?']);
        $result = t\seq($data, $xf);
        $this->assertEquals($result, ['You', 'there', 'guy', '?']);
    }

    public function testKeeps()
    {
        $data = [0, false, null, true];
        $xf = t\keep(function ($value) { return $value; });
        $result = t\seq($data, $xf);
        $this->assertEquals([0, false, true], $result);
    }

    public function testKeepsWithIndex()
    {
        $data = [0, false, null, true];
        $calls = [];
        $xf = t\keep_indexed(function ($idx, $item) use (&$calls) {
            $calls[] = [$idx, $item];
            return $item;
        });
        $result = t\seq($data, $xf);
        $this->assertEquals([0, false, true], $result);
        $this->assertEquals([[0, 0], [1, false], [2, null], [3, true]], $calls);
    }

    public function testVecReturnsArrays()
    {
        $this->assertEquals([1, 2, 3], t\vec([1, 2, 3]));
        $this->assertEquals([['a', 1], ['b', 2]], iterator_to_array(t\vec(['a' => 1, 'b' => 2])));
    }

    public function testVecReturnsStreamsIter()
    {
        $s = fopen('php://temp', 'w+');
        fwrite($s, 'foo');
        rewind($s);
        $this->assertEquals(['f', 'o','o'], iterator_to_array(t\vec($s)));
        fclose($s);
    }

    public function testVecReturnsStringAsArray()
    {
        $this->assertEquals(['f', 'o','o'], t\vec('foo'));
    }

    public function testVecReturnsIteratorAsIs()
    {
        $i = new \ArrayIterator([1, 2]);
        $this->assertSame($i, t\vec($i));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Do not know how to vec collection: stdClass
     */
    public function testVecEnsuresItCanHandleType()
    {
        $o = new \stdClass();
        t\vec($o);
    }

    public function testConvertsToArray()
    {
        $this->assertEquals(
            [1, 2],
            t\to_array([1, 2], t\compact())
        );
        $this->assertEquals(
            [1, 2],
            t\to_array(new \ArrayIterator([1, 2]), t\compact())
        );
        $this->assertEquals(
            ['a', 'b'],
            t\to_array('ab', t\compact())
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Do not know how to vec collection: 1
     */
    public function testConvertsToArrayThrowsWhenInvalidType()
    {
        t\to_array(1, function () {});
    }

    public function testReducedConstructor()
    {
        $r = new t\Reduced('foo');
        $this->assertEquals('foo', $r->value);
    }

    public function testCreatesReducers()
    {
        $t = t\create_reducer(
            function ($r, $x) { return $r . $x; },
            function () { return ''; }
        );
        $this->assertEquals('', $t['init']());
        $this->assertEquals('ab', $t['step']('a', 'b'));
        $this->assertEquals('foo', $t['result']('foo'));
    }

    public function testChecksIfIterable()
    {
        $this->assertTrue(t\is_iterable([1, 2]));
        $this->assertTrue(t\is_iterable(new \ArrayObject([1, 2])));
        $this->assertTrue(t\is_iterable(new \stdClass()));
        $this->assertFalse(t\is_iterable('a'));
    }
}

<?php
namespace transducers\Tests;

use transducers as t;

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

    public function testSeqAppliesToIterator()
    {
        $xf = t\compact();
        $data = new \ArrayIterator([1, false, 2, null]);
        $iter = t\seq($data, $xf);
        $this->assertInstanceOf('Generator', $iter);
        $this->assertEquals([1, 2], iterator_to_array($iter));
    }

    public function testSeqAppliesToString()
    {
        $xf = t\map(function ($v) { return strtoupper($v); });
        $data = 'foo';
        $this->assertSame('FOO', t\seq($data, $xf));
    }

    /**
     * @expectedExceptionMessage Do not know how to seq collection
     * @expectedException \InvalidArgumentException
     */
    public function testSeqThrowsWhenUnknownDataType()
    {
        t\seq(false, t\compact());
    }

    public function testCompactTrimsFalseyValues()
    {
        $data = [0, false, true, 10, ' ', 'a'];
        $result = t\into([], $data, t\compact());
        $this->assertEquals([true, 10, ' ', 'a'], $result);
    }

    public function testTapsIntoReduce()
    {
        $data = ['a', 'b', 'c'];
        $res = [];
        $result = t\into([], $data, t\tap(function ($r, $x) use (&$res) {
            $res[] = $x;
        }));
        $this->assertSame($res, $result);
    }

    public function testInterposes()
    {
        $data = ['a', 'b', 'c'];
        $result = t\into([], $data, t\interpose('-'));
        $this->assertEquals(['a', '-', 'b', '-', 'c'], $result);
    }

    public function testRemovesDuplicates()
    {
        $data = ['a', 'b', 'b', 'c', 'c', 'c', 'b'];
        $result = t\into([], $data, t\dedupe());
        $this->assertEquals(['a', 'b', 'c', 'b'], $result);
    }

    public function testMaps()
    {
        $data = ['a', 'b', 'c'];
        $xf = t\map(function ($value) { return strtoupper($value); });
        $result = t\into([], $data, $xf);
        $this->assertEquals(['A', 'B', 'C'], $result);
    }

    public function testFilters()
    {
        $data = [1, 2, 3, 4];
        $odd = function ($value) { return $value % 2; };
        $result = t\into([], $data, t\filter($odd));
        $this->assertEquals([1, 3], $result);
    }

    public function testRemoves()
    {
        $data = [1, 2, 3, 4];
        $odd = function ($value) { return $value % 2; };
        $result = t\into([], $data, t\remove($odd));
        $this->assertEquals([2, 4], $result);
    }

    public function testCats()
    {
        $data = [[1, 2], 3, [], [4, 5]];
        $result = t\into([], $data, 'transducers\cat');
        $this->assertEquals($result, [1, 2, 3, 4, 5]);
    }

    public function testMapCats()
    {
        $data = [[1, 2], [3], [], [4, 5]];
        $xf = t\mapcat(function ($value) { return array_sum($value); });
        $result = t\into([], $data, $xf);
        $this->assertEquals($result, [3, 3, 0, 9]);
    }

    public function testFlattensIterables()
    {
        $data = [[1, 2], [3, [4, 5, new \ArrayObject([6, 7])]], [], [8, 9]];
        $result = t\into([], $data, t\flatten());
        $this->assertEquals($result, [1, 2, 3, 4, 5, 6, 7, 8, 9]);
    }

    public function testFlattenSkipsNonIterables()
    {
        $data = ['abc'];
        $result = t\into([], $data, t\flatten());
        $this->assertEquals($result, ['abc']);
    }

    public function testPartitions()
    {
        $data = [1, 2, 3, 4, 5];
        $xf = t\partition(2);
        $result = t\into([], $data, $xf);
        $this->assertEquals($result, [[1, 2], [3, 4], [5]]);
    }

    public function testPartitionsByPredicate()
    {
        $data = [['a', 1], ['a', 2], ['a', 3], [2, 4], ['c', 5]];
        $xf = t\partition_by(function ($v) { return is_string($v[0]); });
        $result = t\into([], $data, $xf);
        $this->assertEquals(
            $result,
            [[['a', 1], ['a', 2], ['a', 3]], [[2, 4]], [['c', 5]]]
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
        $this->assertTrue(t\is_iterable(new \ArrayIterator([1, 2])));
        $this->assertTrue(t\is_iterable(new \stdClass()));
        $this->assertFalse(t\is_iterable('a'));
    }

    public function testHasOperatorReducer()
    {
        $xf = t\compact();
        $data = [1, 2, 3];
        $this->assertEquals(6, t\transduce($xf, t\operator_reducer('+'), $data));
        $this->assertEquals(-6, t\transduce($xf, t\operator_reducer('-'), $data));
        $this->assertEquals(0, t\transduce($xf, t\operator_reducer('*'), $data));
        $this->assertEquals(6, t\transduce($xf, t\operator_reducer('*'), $data, 1));
        $this->assertEquals(0.16666666666666666, t\transduce($xf, t\operator_reducer('/'), $data, 1));
        $this->assertEquals('123', t\transduce($xf, t\operator_reducer('.'), $data));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOperatorIsValid()
    {
        t\operator_reducer('!');
    }

    public function testReducesToString()
    {
        $xf = t\map(function ($v) { return strtoupper($v); });
        $data = ['a', 'b', 'c'];
        $this->assertEquals('ABC', t\transduce($xf, t\string_reducer(), $data));
    }

    public function testReducesToAssoc()
    {
        $xf = t\map(function ($v) {
            return [strtoupper($v[0]), $v[1]];
        });
        $data = ['a' => 1, 'b' => 2];
        $result = t\transduce($xf, t\assoc_reducer(), t\assoc_iter($data));
        $this->assertEquals(['A' => 1, 'B' => 2], $result);
    }

    public function testSplitsWords()
    {
        $this->assertEquals(
            ['hi', 'there', 'guy!'],
            t\seq(["hi\nthere", " guy!"], t\words())
        );
        $this->assertEquals(
            ['hi', 'the', 're', 'guy', '!'],
            t\seq(["hi\nthere", " guy!"],t\words(3))
        );
    }

    public function testSplitsLines()
    {
        $this->assertEquals(
            ['hi', 'there guy!'],
            t\seq(["hi\nthere", " guy!"], t\lines())
        );
        $this->assertEquals(
            ['hi', 'there', ' guy!'],
            t\seq(["hi\nthere", " guy!"], t\lines(5))
        );
    }

    //-----------------------------------------------------------------------------
    // Stream tests
    //-----------------------------------------------------------------------------

    private function stream($str)
    {
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $str);
        rewind($fp);
        return $fp;
    }

    public function addsProvider()
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider addsProvider
     */
    public function testAddsWhenWriting($append)
    {
        $called = [];
        $fp = fopen('php://memory', 'r+');
        $xf = t\map(function ($v) use (&$called) {
            $called[] = $v;
            return strtoupper($v);
        });
        if ($append) {
            t\append_stream_filter($fp, $xf, STREAM_FILTER_WRITE);
        } else {
            t\prepend_stream_filter($fp, $xf, STREAM_FILTER_WRITE);
        }
        fwrite($fp, 'foo');
        fwrite($fp, 'bar');
        $this->assertEquals(['f', 'o', 'o', 'b', 'a', 'r'], $called);
        rewind($fp);
        $this->assertEquals('FOOBAR', stream_get_contents($fp));
    }

    public function testAddsWhenReading()
    {
        $called = [];
        $fp = $this->stream('foobar');
        $xf = t\map(function ($v) use (&$called) {
            $called[] = $v;
            return strtoupper($v);
        });
        $filter = t\append_stream_filter($fp, $xf, STREAM_FILTER_READ);
        $this->assertInternalType('resource', $filter);
        $this->assertEquals('stream filter', get_resource_type($filter));
        $this->assertEquals('FOOBAR', stream_get_contents($fp));
        $this->assertEquals(['f', 'o', 'o', 'b', 'a', 'r'], $called);
    }

    public function testCanEarlyTerminate()
    {
        $fp = $this->stream('foobar');
        $called = [];
        $xf = t\comp(
            t\take(3),
            t\tap(function ($r, $x) use (&$called) {
                $called[] = $x;
            })
        );
        t\append_stream_filter($fp, $xf, STREAM_FILTER_READ);
        $this->assertEquals('foo', stream_get_contents($fp));
        $this->assertEquals(['f', 'o', 'o'], $called);
    }

    public function testCanStepInClosing()
    {
        $fp = $this->stream('hi there guy');
        $xf = t\comp(
            t\partition_by(function ($v) { return $v !== ' '; }),
            t\filter(function ($v) { return $v !== [' ']; }),
            t\keep_indexed(function ($i, $v) {
                $str = implode('', $v);
                if ($i % 2) {
                    return strtoupper($str);
                } else {
                    return strtolower($str);
                }
            }),
            t\interpose(' ')
        );
        $filter = t\append_stream_filter($fp, $xf, STREAM_FILTER_READ);
        $this->assertEquals('hi THERE', stream_get_contents($fp));
        // Note that the last bit requires the filter to be removed!
        stream_filter_remove($filter);
        $this->assertEquals(' guy', fread($fp, 100));
    }

    /**
     * @expectedException \PHPUnit_Framework_Error_Notice
     * @expectedExceptionMessage Filter params arg must be a transducer function
     */
    public function testEnsuresXfIscallable()
    {
        $fp = $this->stream('foo');
        t\register_stream_filter();
        stream_filter_append($fp, 'transducer', STREAM_FILTER_READ);
    }

    public function testToFn()
    {
        $xf = t\map(function ($x) { return $x + 1; });
        $fn = t\to_fn($xf, t\string_reducer());
        $result = array_reduce([1, 2, 3], $fn);
        $this->assertEquals('234', $result);
    }
}

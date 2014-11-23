<?php
namespace transducers\Tests;

use transducers as t;
use transducers\streams as s;

class streamsTest extends \PHPUnit_Framework_TestCase
{
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
            s\append_filter($fp, $xf, STREAM_FILTER_WRITE);
        } else {
            s\prepend_filter($fp, $xf, STREAM_FILTER_WRITE);
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
        $filter = s\append_filter($fp, $xf, STREAM_FILTER_READ);
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
        s\append_filter($fp, $xf, STREAM_FILTER_READ);
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
        $filter = s\append_filter($fp, $xf, STREAM_FILTER_READ);
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
        s\register_stream_filter();
        stream_filter_append($fp, 'transducer', STREAM_FILTER_READ);
    }
}

<?php
declare(strict_types=1);
namespace AMQP10\Tests\Protocol;

use AMQP10\Exception\FrameException;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;
use PHPUnit\Framework\TestCase;

class TypeDecoderTest extends TestCase
{
    public function test_decode_null(): void
    {
        $decoder = new TypeDecoder("\x40");
        $this->assertNull($decoder->decode());
    }

    public function test_decode_bool_true_shortform(): void
    {
        $decoder = new TypeDecoder("\x41");
        $this->assertTrue($decoder->decode());
    }

    public function test_decode_bool_false_shortform(): void
    {
        $decoder = new TypeDecoder("\x42");
        $this->assertFalse($decoder->decode());
    }

    public function test_decode_uint_zero(): void
    {
        $decoder = new TypeDecoder("\x43");
        $this->assertSame(0, $decoder->decode());
    }

    public function test_decode_smallulong(): void
    {
        $decoder = new TypeDecoder("\x53\x10");
        $this->assertSame(0x10, $decoder->decode());
    }

    public function test_decode_str8(): void
    {
        $decoder = new TypeDecoder("\xa1\x04test");
        $this->assertSame('test', $decoder->decode());
    }

    public function test_decode_symbol(): void
    {
        $decoder = new TypeDecoder("\xa3\x05PLAIN");
        $this->assertSame('PLAIN', $decoder->decode());
    }

    public function test_decode_empty_list(): void
    {
        $decoder = new TypeDecoder("\x45");
        $this->assertSame([], $decoder->decode());
    }

    public function test_decode_list8_with_one_null(): void
    {
        $decoder = new TypeDecoder("\xc0\x02\x01\x40");
        $this->assertSame([null], $decoder->decode());
    }

    public function test_decode_list8_with_mixed_types(): void
    {
        // list [null, "hi"]: size=6, count=2
        // size = 1(count) + 1(null) + 4(str8 "hi") = 6
        $data = "\xc0\x06\x02\x40\xa1\x02hi";
        $decoder = new TypeDecoder($data);
        $result = $decoder->decode();
        $this->assertSame([null, 'hi'], $result);
    }

    public function test_decode_described_type(): void
    {
        // Described: \x00 + smallulong(0x10) + list0
        $decoder = new TypeDecoder("\x00\x53\x10\x45");
        $result = $decoder->decode();
        $this->assertSame(0x10, $result['descriptor']);
        $this->assertSame([], $result['value']);
    }

    public function test_roundtrip_string(): void
    {
        $original = 'hello world';
        $encoded  = TypeEncoder::encodeString($original);
        $decoder  = new TypeDecoder($encoded);
        $this->assertSame($original, $decoder->decode());
    }

    public function test_roundtrip_ulong(): void
    {
        $original = 0x10;
        $encoded  = TypeEncoder::encodeUlong($original);
        $decoder  = new TypeDecoder($encoded);
        $this->assertSame($original, $decoder->decode());
    }

    public function test_multiple_sequential_decodes(): void
    {
        $data    = TypeEncoder::encodeNull() . TypeEncoder::encodeString('hi');
        $decoder = new TypeDecoder($data);
        $this->assertNull($decoder->decode());
        $this->assertSame('hi', $decoder->decode());
    }

    public function test_remaining_bytes_after_decode(): void
    {
        $data    = TypeEncoder::encodeNull() . TypeEncoder::encodeString('extra');
        $decoder = new TypeDecoder($data);
        $decoder->decode(); // consume null
        $this->assertGreaterThan(0, $decoder->remaining());
    }

    public function test_rejects_oversized_var32(): void
    {
        // str32 with length > 1MB: type(1) + len(4) = 5 bytes header
        $len = 1_048_577; // MAX_VAR_SIZE + 1
        $data = pack('CN', 0xB1, $len); // STR32 constructor + oversized length
        $decoder = new TypeDecoder($data);

        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('exceeds maximum');
        $decoder->decode();
    }

    public function test_rejects_odd_map_count(): void
    {
        // map8: constructor(1) + size(1) + count(1) + items
        // Manually craft a map8 with odd count = 3
        $data = pack('CCC', 0xC1, 4, 3) . "\x40\x40\x40"; // 3 nulls
        $decoder = new TypeDecoder($data);

        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('even');
        $decoder->decode();
    }
}

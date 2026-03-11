<?php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\TypeEncoder;
use PHPUnit\Framework\TestCase;

class TypeEncoderTest extends TestCase
{
    public function test_encode_null(): void
    {
        $this->assertSame("\x40", TypeEncoder::encodeNull());
    }

    public function test_encode_bool_true_uses_shortform(): void
    {
        $this->assertSame("\x41", TypeEncoder::encodeBool(true));
    }

    public function test_encode_bool_false_uses_shortform(): void
    {
        $this->assertSame("\x42", TypeEncoder::encodeBool(false));
    }

    public function test_encode_uint_zero_uses_uint0(): void
    {
        $this->assertSame("\x43", TypeEncoder::encodeUint(0));
    }

    public function test_encode_small_uint_uses_smalluint(): void
    {
        $this->assertSame("\x52\x01", TypeEncoder::encodeUint(1));
        $this->assertSame("\x52\xff", TypeEncoder::encodeUint(255));
    }

    public function test_encode_large_uint_uses_four_bytes(): void
    {
        $this->assertSame("\x70\x00\x00\x01\x00", TypeEncoder::encodeUint(256));
    }

    public function test_encode_ulong_zero_uses_ulong0(): void
    {
        $this->assertSame("\x44", TypeEncoder::encodeUlong(0));
    }

    public function test_encode_small_ulong_uses_smallulong(): void
    {
        $this->assertSame("\x53\x10", TypeEncoder::encodeUlong(0x10));
        $this->assertSame("\x53\xff", TypeEncoder::encodeUlong(255));
    }

    public function test_encode_large_ulong_uses_eight_bytes(): void
    {
        $this->assertSame("\x80\x00\x00\x00\x00\x00\x00\x01\x00", TypeEncoder::encodeUlong(256));
    }

    public function test_encode_ubyte(): void
    {
        $this->assertSame("\x50\x7f", TypeEncoder::encodeUbyte(127));
    }

    public function test_encode_ushort(): void
    {
        $this->assertSame("\x60\x01\x00", TypeEncoder::encodeUshort(256));
    }

    public function test_encode_short_string_uses_str8(): void
    {
        $this->assertSame("\xa1\x04test", TypeEncoder::encodeString('test'));
    }

    public function test_encode_255_byte_string_uses_str8(): void
    {
        $str = str_repeat('a', 255);
        $encoded = TypeEncoder::encodeString($str);
        $this->assertSame("\xa1\xff", substr($encoded, 0, 2));
        $this->assertSame(2 + 255, strlen($encoded));
    }

    public function test_encode_256_byte_string_uses_str32(): void
    {
        $str = str_repeat('a', 256);
        $encoded = TypeEncoder::encodeString($str);
        $this->assertSame("\xb1\x00\x00\x01\x00", substr($encoded, 0, 5));
        $this->assertSame(5 + 256, strlen($encoded));
    }

    public function test_encode_symbol(): void
    {
        $this->assertSame("\xa3\x05PLAIN", TypeEncoder::encodeSymbol('PLAIN'));
    }

    public function test_encode_binary(): void
    {
        $this->assertSame("\xa0\x03\x00\x01\x02", TypeEncoder::encodeBinary("\x00\x01\x02"));
    }

    public function test_encode_empty_list_uses_list0(): void
    {
        $this->assertSame("\x45", TypeEncoder::encodeList([]));
    }

    public function test_encode_list_with_one_null(): void
    {
        // list8 (0xc0): size(uint8) + count(uint8) + items
        // size = 1 (count byte) + 1 (null byte) = 2
        $this->assertSame("\xc0\x02\x01\x40", TypeEncoder::encodeList(["\x40"]));
    }

    public function test_encode_list_size_covers_count_plus_items(): void
    {
        // Two nulls: size = 1 (count) + 2 (nulls) = 3, count = 2
        $this->assertSame("\xc0\x03\x02\x40\x40", TypeEncoder::encodeList(["\x40", "\x40"]));
    }

    public function test_encode_symbol_array(): void
    {
        // array8 (0xe0): size + count + constructor(sym8) + element-payloads
        // For ["PLAIN"]: count=1, constructor=0xa3, payload=\x05PLAIN
        // size = 1 (count) + 1 (constructor) + 1 (length) + 5 (PLAIN) = 8
        $encoded = TypeEncoder::encodeSymbolArray(['PLAIN']);
        $this->assertSame("\xe0", $encoded[0]);   // array8
        $this->assertSame(8, ord($encoded[1]));   // size=8
        $this->assertSame(1, ord($encoded[2]));   // count=1
        $this->assertSame("\xa3", $encoded[3]);   // sym8 constructor
        $this->assertSame("\x05PLAIN", substr($encoded, 4));
    }

    public function test_encode_described_type(): void
    {
        $descriptor = TypeEncoder::encodeUlong(0x10); // "\x53\x10"
        $value = TypeEncoder::encodeNull();            // "\x40"
        $this->assertSame("\x00\x53\x10\x40", TypeEncoder::encodeDescribed($descriptor, $value));
    }
}

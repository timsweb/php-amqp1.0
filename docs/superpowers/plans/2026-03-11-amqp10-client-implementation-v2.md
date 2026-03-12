# AMQP 1.0 PHP Client Implementation Plan (v2 — Corrected)

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a modern PHP 8.1+ AMQP 1.0 client library for RabbitMQ 4.0+ with a correct protocol implementation, fluent APIs, and wide runtime support.

**Architecture:** Layered: Protocol Layer (type encoding, frame parsing, performatives, SASL) → Transport Layer (I/O adapters) → Connection Layer (AMQP open/close) → Session + Link Layer (AMQP 1.0 sessions and links are first-class, not bolted on) → Messaging Layer (messages, publishers, consumers) → Client API facade.

**Tech Stack:** PHP 8.1+, Composer, PHPUnit 10+. No external protocol dependencies — AMQP 1.0 implemented from scratch against the OASIS spec.

**Reference specs:**
- AMQP 1.0 Transport: https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-transport-v1.0-os.html
- AMQP 1.0 Types: https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-types-v1.0-os.html
- AMQP 1.0 Messaging: https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-messaging-v1.0-os.html
- AMQP 1.0 Security (SASL): https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-security-v1.0-os.html
- RabbitMQ AMQP 1.0: https://www.rabbitmq.com/docs/amqp

---

## Chunk 1: Foundation (COMPLETE)

Already implemented and tests passing. In place:
- `composer.json` (PSR-4: `AMQP10\` → `src/AMQP10/`)
- `phpunit.xml`
- Exception hierarchy (`src/AMQP10/Exception/` — one class per file)
- `src/AMQP10/Client/Config.php`
- All existing tests pass: `./vendor/bin/phpunit`

---

## Chunk 2: Protocol Layer — Type Encoding

AMQP 1.0 uses a self-describing binary encoding. Every value on the wire starts with a **type constructor byte** that identifies its type and encoding. This chunk implements encoding and decoding of all AMQP primitive types.

**Key spec section:** https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-types-v1.0-os.html §1.6

### File map

- Create: `src/AMQP10/Protocol/TypeCode.php` — type constructor byte constants
- Create: `src/AMQP10/Protocol/TypeEncoder.php` — encodes PHP values to AMQP wire bytes
- Create: `src/AMQP10/Protocol/TypeDecoder.php` — decodes AMQP wire bytes to PHP values
- Create: `tests/Unit/Protocol/TypeEncoderTest.php`
- Create: `tests/Unit/Protocol/TypeDecoderTest.php`

---

### Task 2.1: TypeCode Constants

**Files:**
- Create: `src/AMQP10/Protocol/TypeCode.php`

- [ ] **Step 1: Create TypeCode**

```php
<?php
// src/AMQP10/Protocol/TypeCode.php
namespace AMQP10\Protocol;

/**
 * AMQP 1.0 type constructor byte constants.
 * Spec: §1.6 Primitive Type Definitions
 */
final class TypeCode
{
    // Null (§1.6.1)
    public const NULL = 0x40;

    // Boolean short-forms (§1.6.2)
    public const BOOL_TRUE  = 0x41; // true, no payload
    public const BOOL_FALSE = 0x42; // false, no payload
    public const BOOL       = 0x56; // 1-byte payload: 0x00=false, 0x01=true

    // Unsigned integer short-forms (§1.6.3–1.6.6)
    public const UINT_ZERO  = 0x43; // zero, no payload
    public const UINT_SMALL = 0x52; // 1-byte payload (0–255)
    public const UINT       = 0x70; // 4-byte payload

    public const ULONG_ZERO  = 0x44; // zero, no payload
    public const ULONG_SMALL = 0x53; // 1-byte payload (0–255)
    public const ULONG       = 0x80; // 8-byte payload

    // Other unsigned
    public const UBYTE  = 0x50; // 1-byte payload
    public const USHORT = 0x60; // 2-byte payload

    // Signed integers (§1.6.7–1.6.12)
    public const BYTE       = 0x51; // 1-byte signed
    public const SHORT      = 0x61; // 2-byte signed
    public const INT_SMALL  = 0x54; // 1-byte signed payload
    public const INT        = 0x71; // 4-byte signed payload
    public const LONG_SMALL = 0x55; // 1-byte signed payload
    public const LONG       = 0x81; // 8-byte signed payload

    // Floating point (§1.6.13–1.6.14)
    public const FLOAT  = 0x72; // 4-byte IEEE 754
    public const DOUBLE = 0x82; // 8-byte IEEE 754

    // Other primitives
    public const CHAR      = 0x73; // 4-byte UTF-32 code point
    public const TIMESTAMP = 0x83; // 8-byte ms since Unix epoch (signed long)
    public const UUID      = 0x98; // 16 bytes

    // Variable-width (§1.6.18–1.6.21)
    public const VBIN8  = 0xa0; // binary: 1-byte length prefix
    public const VBIN32 = 0xb0; // binary: 4-byte length prefix
    public const STR8   = 0xa1; // UTF-8 string: 1-byte length prefix (max 255 bytes)
    public const STR32  = 0xb1; // UTF-8 string: 4-byte length prefix
    public const SYM8   = 0xa3; // ASCII symbol: 1-byte length prefix (max 255 bytes)
    public const SYM32  = 0xb3; // ASCII symbol: 4-byte length prefix

    // Compound types (§1.6.22–1.6.25)
    public const LIST0  = 0x45; // empty list, no payload
    public const LIST8  = 0xc0; // 1-byte size + 1-byte count + items
    public const LIST32 = 0xd0; // 4-byte size + 4-byte count + items
    public const MAP8   = 0xc1; // 1-byte size + 1-byte count + alternating key/value
    public const MAP32  = 0xd1; // 4-byte size + 4-byte count

    // Array (§1.6.26–1.6.27) — all elements share one constructor
    public const ARRAY8  = 0xe0; // 1-byte size + 1-byte count + constructor + payloads
    public const ARRAY32 = 0xf0; // 4-byte size + 4-byte count + constructor + payloads

    // Described type marker (§1.6.28)
    // Format: 0x00 + descriptor (any AMQP value) + value (any AMQP value)
    public const DESCRIBED = 0x00;
}
```

- [ ] **Step 2: Run tests (none yet — verifying class exists)**

```bash
./vendor/bin/phpunit
```

Expected: 2 tests pass (existing tests unaffected)

- [ ] **Step 3: Commit**

```bash
git add src/AMQP10/Protocol/TypeCode.php
git commit -m "feat: add AMQP 1.0 type code constants"
```

---

### Task 2.2: TypeEncoder

**Files:**
- Create: `src/AMQP10/Protocol/TypeEncoder.php`
- Create: `tests/Unit/Protocol/TypeEncoderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Protocol/TypeEncoderTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\TypeEncoder;
use PHPUnit\Framework\TestCase;

class TypeEncoderTest extends TestCase
{
    // --- Null ---

    public function test_encode_null(): void
    {
        $this->assertSame("\x40", TypeEncoder::encodeNull());
    }

    // --- Boolean ---

    public function test_encode_bool_true_uses_shortform(): void
    {
        $this->assertSame("\x41", TypeEncoder::encodeBool(true));
    }

    public function test_encode_bool_false_uses_shortform(): void
    {
        $this->assertSame("\x42", TypeEncoder::encodeBool(false));
    }

    // --- Unsigned integers ---

    public function test_encode_uint_zero_uses_uint0(): void
    {
        $this->assertSame("\x43", TypeEncoder::encodeUint(0));
    }

    public function test_encode_small_uint_uses_smalluint(): void
    {
        // smalluint (0x52): values 1–255
        $this->assertSame("\x52\x01", TypeEncoder::encodeUint(1));
        $this->assertSame("\x52\xff", TypeEncoder::encodeUint(255));
    }

    public function test_encode_large_uint_uses_four_bytes(): void
    {
        // 256 needs full uint (0x70)
        $this->assertSame("\x70\x00\x00\x01\x00", TypeEncoder::encodeUint(256));
    }

    public function test_encode_ulong_zero_uses_ulong0(): void
    {
        $this->assertSame("\x44", TypeEncoder::encodeUlong(0));
    }

    public function test_encode_small_ulong_uses_smallulong(): void
    {
        // smallulong (0x53): values 1–255
        // Descriptor codes like 0x10 (open) are encoded this way
        $this->assertSame("\x53\x10", TypeEncoder::encodeUlong(0x10));
        $this->assertSame("\x53\xff", TypeEncoder::encodeUlong(255));
    }

    public function test_encode_large_ulong_uses_eight_bytes(): void
    {
        // 256 needs full ulong (0x80): 8 bytes big-endian
        // 256 = 0x0000000000000100
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

    // --- Strings and symbols ---

    public function test_encode_short_string_uses_str8(): void
    {
        // str8 (0xa1): 1-byte length, then UTF-8 bytes
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
        // str32 (0xb1): 4-byte length, then UTF-8 bytes
        $str = str_repeat('a', 256);
        $encoded = TypeEncoder::encodeString($str);
        $this->assertSame("\xb1\x00\x00\x01\x00", substr($encoded, 0, 5));
        $this->assertSame(5 + 256, strlen($encoded));
    }

    public function test_encode_symbol(): void
    {
        // sym8 (0xa3): 1-byte length, then ASCII bytes
        $this->assertSame("\xa3\x05PLAIN", TypeEncoder::encodeSymbol('PLAIN'));
    }

    public function test_encode_binary(): void
    {
        // vbin8 (0xa0): 1-byte length, then bytes
        $this->assertSame("\xa0\x03\x00\x01\x02", TypeEncoder::encodeBinary("\x00\x01\x02"));
    }

    // --- Collections ---

    public function test_encode_empty_list_uses_list0(): void
    {
        $this->assertSame("\x45", TypeEncoder::encodeList([]));
    }

    public function test_encode_list_with_one_null(): void
    {
        // list8 (0xc0): size(uint8) + count(uint8) + items
        // size = 1 (count byte) + 1 (null byte) = 2
        // count = 1
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
        $this->assertSame("\x05PLAIN", substr($encoded, 4)); // length + symbol
    }

    // --- Described types ---

    public function test_encode_described_type(): void
    {
        // Described type: \x00 + descriptor + value
        // Used for performatives: e.g., \x00 + smallulong(0x10) + list = OPEN performative
        $descriptor = TypeEncoder::encodeUlong(0x10); // "\x53\x10"
        $value = TypeEncoder::encodeNull();            // "\x40"
        $this->assertSame("\x00\x53\x10\x40", TypeEncoder::encodeDescribed($descriptor, $value));
    }
}
```

- [ ] **Step 2: Run tests to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeEncoderTest.php
```

Expected: FAIL — `TypeEncoder` not found

- [ ] **Step 3: Implement TypeEncoder**

```php
<?php
// src/AMQP10/Protocol/TypeEncoder.php
namespace AMQP10\Protocol;

class TypeEncoder
{
    public static function encodeNull(): string
    {
        return "\x40";
    }

    public static function encodeBool(bool $value): string
    {
        return $value ? "\x41" : "\x42";
    }

    public static function encodeUbyte(int $value): string
    {
        return pack('CC', TypeCode::UBYTE, $value & 0xFF);
    }

    public static function encodeUshort(int $value): string
    {
        return pack('Cn', TypeCode::USHORT, $value & 0xFFFF);
    }

    public static function encodeUint(int $value): string
    {
        if ($value === 0) {
            return "\x43"; // uint0
        }
        if ($value <= 0xFF) {
            return pack('CC', TypeCode::UINT_SMALL, $value);
        }
        return pack('CN', TypeCode::UINT, $value);
    }

    public static function encodeUlong(int $value): string
    {
        if ($value === 0) {
            return "\x44"; // ulong0
        }
        if ($value <= 0xFF) {
            return pack('CC', TypeCode::ULONG_SMALL, $value);
        }
        // 8-byte big-endian: split into two uint32
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low  = $value & 0xFFFFFFFF;
        return pack('C', TypeCode::ULONG) . pack('NN', $high, $low);
    }

    public static function encodeString(string $value): string
    {
        $len = strlen($value);
        if ($len <= 0xFF) {
            return pack('CC', TypeCode::STR8, $len) . $value;
        }
        return pack('CN', TypeCode::STR32, $len) . $value;
    }

    public static function encodeSymbol(string $value): string
    {
        $len = strlen($value);
        if ($len <= 0xFF) {
            return pack('CC', TypeCode::SYM8, $len) . $value;
        }
        return pack('CN', TypeCode::SYM32, $len) . $value;
    }

    public static function encodeBinary(string $value): string
    {
        $len = strlen($value);
        if ($len <= 0xFF) {
            return pack('CC', TypeCode::VBIN8, $len) . $value;
        }
        return pack('CN', TypeCode::VBIN32, $len) . $value;
    }

    /**
     * Encode a list of pre-encoded AMQP values.
     *
     * list8:  constructor(1) + size(1) + count(1) + items
     *   size = count-byte(1) + len(items)
     * list32: constructor(1) + size(4) + count(4) + items
     *   size = count-bytes(4) + len(items)
     *
     * @param string[] $items Pre-encoded AMQP values (call TypeEncoder methods to produce these)
     */
    public static function encodeList(array $items): string
    {
        if (empty($items)) {
            return "\x45"; // list0
        }

        $body  = implode('', $items);
        $count = count($items);
        $size8 = 1 + strlen($body); // 1 = count byte

        if ($size8 <= 0xFF && $count <= 0xFF) {
            return pack('CCC', TypeCode::LIST8, $size8, $count) . $body;
        }

        $size32 = 4 + strlen($body); // 4 = count uint32
        return pack('CNN', TypeCode::LIST32, $size32, $count) . $body;
    }

    /**
     * Encode a map of pre-encoded key=>value AMQP pairs.
     *
     * map8:  constructor(1) + size(1) + count(1) + alternating-key-value-pairs
     *   size = count-byte(1) + len(pairs)
     *   count = number of key+value items (i.e., pairs * 2)
     *
     * @param array<string, string> $pairs Pre-encoded key => pre-encoded value
     */
    public static function encodeMap(array $pairs): string
    {
        $body  = '';
        $count = 0;
        foreach ($pairs as $key => $value) {
            $body  .= $key . $value;
            $count += 2;
        }

        if (empty($pairs)) {
            $size8 = 1;
            return pack('CCC', TypeCode::MAP8, $size8, 0);
        }

        $size8 = 1 + strlen($body);
        if ($size8 <= 0xFF && $count <= 0xFF) {
            return pack('CCC', TypeCode::MAP8, $size8, $count) . $body;
        }

        $size32 = 4 + strlen($body);
        return pack('CNN', TypeCode::MAP32, $size32, $count) . $body;
    }

    /**
     * Encode an array of symbols sharing the sym8 constructor.
     *
     * array8: constructor(1) + size(1) + count(1) + element-constructor(1) + payloads
     *   size = count(1) + element-constructor(1) + len(all-payloads)
     *   Each sym8 element payload: length(1) + ascii-bytes
     *
     * @param string[] $symbols
     */
    public static function encodeSymbolArray(array $symbols): string
    {
        $elementPayloads = '';
        foreach ($symbols as $sym) {
            $elementPayloads .= pack('C', strlen($sym)) . $sym;
        }

        $count = count($symbols);
        // size = count(1) + constructor(1) + all element payloads
        $size = 1 + 1 + strlen($elementPayloads);

        if ($size <= 0xFF && $count <= 0xFF) {
            return pack('CCC', TypeCode::ARRAY8, $size, $count)
                 . pack('C', TypeCode::SYM8)
                 . $elementPayloads;
        }

        // array32: size(4) + count(4) + constructor(1) + payloads
        $size32 = 4 + 1 + strlen($elementPayloads);
        return pack('CNN', TypeCode::ARRAY32, $size32, $count)
             . pack('C', TypeCode::SYM8)
             . $elementPayloads;
    }

    /**
     * Wrap a pre-encoded descriptor and value as a described type.
     * Format: 0x00 + descriptor + value
     *
     * Used for all performatives, e.g.:
     *   encodeDescribed(encodeUlong(0x10), encodeList([...])) = OPEN performative
     */
    public static function encodeDescribed(string $descriptor, string $value): string
    {
        return "\x00" . $descriptor . $value;
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeEncoderTest.php
```

Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Protocol/TypeCode.php src/AMQP10/Protocol/TypeEncoder.php tests/Unit/Protocol/TypeEncoderTest.php
git commit -m "feat: add TypeCode constants and TypeEncoder for AMQP 1.0 wire encoding"
```

---

### Task 2.3: TypeDecoder

**Files:**
- Create: `src/AMQP10/Protocol/TypeDecoder.php`
- Create: `tests/Unit/Protocol/TypeDecoderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Protocol/TypeDecoderTest.php
namespace AMQP10\Tests\Protocol;

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
        // smallulong (0x53) used for descriptor codes like 0x10 (OPEN)
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
        // list8: \xc0 + size=2 + count=1 + null
        $decoder = new TypeDecoder("\xc0\x02\x01\x40");
        $this->assertSame([null], $decoder->decode());
    }

    public function test_decode_list8_with_mixed_types(): void
    {
        // list [null, "hi"]: \xc0 + size=7 + count=2 + \x40 + \xa1\x02hi
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
        // Decoder maintains offset across calls
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
}
```

- [ ] **Step 2: Run tests to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeDecoderTest.php
```

Expected: FAIL — `TypeDecoder` not found

- [ ] **Step 3: Implement TypeDecoder**

```php
<?php
// src/AMQP10/Protocol/TypeDecoder.php
namespace AMQP10\Protocol;

use AMQP10\Exception\FrameException;

class TypeDecoder
{
    private int $offset = 0;

    public function __construct(private readonly string $buffer) {}

    /**
     * Decode the next AMQP value from the buffer.
     * Advances the internal offset.
     */
    public function decode(): mixed
    {
        $code = $this->readByte();

        return match ($code) {
            TypeCode::NULL       => null,
            TypeCode::BOOL_TRUE  => true,
            TypeCode::BOOL_FALSE => false,
            TypeCode::BOOL       => $this->readByte() !== 0,

            TypeCode::UINT_ZERO  => 0,
            TypeCode::UINT_SMALL => $this->readByte(),
            TypeCode::UINT       => $this->readUint32(),

            TypeCode::ULONG_ZERO  => 0,
            TypeCode::ULONG_SMALL => $this->readByte(),
            TypeCode::ULONG       => $this->readUint64(),

            TypeCode::UBYTE  => $this->readByte(),
            TypeCode::USHORT => $this->readUint16(),

            TypeCode::BYTE       => $this->readInt8(),
            TypeCode::SHORT      => $this->readInt16(),
            TypeCode::INT_SMALL  => $this->readInt8(),
            TypeCode::INT        => $this->readInt32(),
            TypeCode::LONG_SMALL => $this->readInt8(),
            TypeCode::LONG       => $this->readInt64(),

            TypeCode::FLOAT  => $this->readFloat(),
            TypeCode::DOUBLE => $this->readDouble(),

            TypeCode::TIMESTAMP => $this->readInt64(),

            TypeCode::STR8, TypeCode::SYM8, TypeCode::VBIN8   => $this->readVar8(),
            TypeCode::STR32, TypeCode::SYM32, TypeCode::VBIN32 => $this->readVar32(),

            TypeCode::LIST0 => [],
            TypeCode::LIST8 => $this->decodeList8(),
            TypeCode::LIST32 => $this->decodeList32(),

            TypeCode::MAP8  => $this->decodeMap8(),
            TypeCode::MAP32 => $this->decodeMap32(),

            TypeCode::ARRAY8  => $this->decodeArray8(),
            TypeCode::ARRAY32 => $this->decodeArray32(),

            TypeCode::DESCRIBED => $this->decodeDescribed(),

            default => throw new FrameException(
                sprintf('Unknown AMQP type code: 0x%02x at offset %d', $code, $this->offset - 1)
            ),
        };
    }

    public function remaining(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    // --- List decoding ---

    private function decodeList8(): array
    {
        $size  = $this->readByte();  // bytes after this byte
        $count = $this->readByte();
        return $this->decodeItems($count);
    }

    private function decodeList32(): array
    {
        $size  = $this->readUint32();
        $count = $this->readUint32();
        return $this->decodeItems($count);
    }

    private function decodeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->decode();
        }
        return $items;
    }

    // --- Map decoding ---

    private function decodeMap8(): array
    {
        $size  = $this->readByte();
        $count = $this->readByte(); // count = pairs * 2
        return $this->decodeMapItems($count);
    }

    private function decodeMap32(): array
    {
        $size  = $this->readUint32();
        $count = $this->readUint32();
        return $this->decodeMapItems($count);
    }

    private function decodeMapItems(int $count): array
    {
        $map = [];
        for ($i = 0; $i < $count; $i += 2) {
            $key       = $this->decode();
            $value     = $this->decode();
            $map[$key] = $value;
        }
        return $map;
    }

    // --- Array decoding ---

    private function decodeArray8(): array
    {
        $size        = $this->readByte();
        $count       = $this->readByte();
        $constructor = $this->readByte(); // shared type code for all elements
        return $this->decodeArrayElements($count, $constructor);
    }

    private function decodeArray32(): array
    {
        $size        = $this->readUint32();
        $count       = $this->readUint32();
        $constructor = $this->readByte();
        return $this->decodeArrayElements($count, $constructor);
    }

    /**
     * Decode $count elements that share a constructor.
     * We re-insert the constructor before each element so decode() can handle them.
     */
    private function decodeArrayElements(int $count, int $constructor): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            // Temporarily prepend constructor byte so decode() dispatches correctly
            $elementDecoder = new self(pack('C', $constructor) . substr($this->buffer, $this->offset));
            $item           = $elementDecoder->decode();
            $this->offset  += $elementDecoder->offset() - 1; // -1 because we prepended 1 byte
            $items[]        = $item;
        }
        return $items;
    }

    // --- Described type ---

    private function decodeDescribed(): array
    {
        $descriptor = $this->decode();
        $value      = $this->decode();
        return ['descriptor' => $descriptor, 'value' => $value];
    }

    // --- Primitive readers ---

    private function readByte(): int
    {
        if ($this->offset >= strlen($this->buffer)) {
            throw new FrameException('Unexpected end of buffer');
        }
        return ord($this->buffer[$this->offset++]);
    }

    private function readInt8(): int
    {
        $v = $this->readByte();
        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    private function readUint16(): int
    {
        $v = unpack('n', substr($this->buffer, $this->offset, 2))[1];
        $this->offset += 2;
        return $v;
    }

    private function readInt16(): int
    {
        $v = $this->readUint16();
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function readUint32(): int
    {
        $v = unpack('N', substr($this->buffer, $this->offset, 4))[1];
        $this->offset += 4;
        return $v;
    }

    private function readInt32(): int
    {
        $v = unpack('N', substr($this->buffer, $this->offset, 4))[1];
        $this->offset += 4;
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function readUint64(): int
    {
        $high = $this->readUint32();
        $low  = $this->readUint32();
        return ($high << 32) | $low;
    }

    private function readInt64(): int
    {
        return $this->readUint64(); // PHP ints are signed on 64-bit
    }

    private function readFloat(): float
    {
        $v = unpack('G', substr($this->buffer, $this->offset, 4))[1];
        $this->offset += 4;
        return $v;
    }

    private function readDouble(): float
    {
        $v = unpack('E', substr($this->buffer, $this->offset, 8))[1];
        $this->offset += 8;
        return $v;
    }

    private function readVar8(): string
    {
        $len    = $this->readByte();
        $value  = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $value;
    }

    private function readVar32(): string
    {
        $len    = $this->readUint32();
        $value  = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $value;
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeDecoderTest.php
```

Expected: All tests pass

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Protocol/TypeDecoder.php tests/Unit/Protocol/TypeDecoderTest.php
git commit -m "feat: add TypeDecoder for AMQP 1.0 wire format decoding"
```

---

## Chunk 3: Protocol Layer — Frame Encoding

Every AMQP 1.0 frame has an 8-byte fixed header (spec §2.3). This is the single biggest error in the original plan — the frame format was completely wrong. This chunk implements the correct header and a frame parser that handles the stream framing needed to read variable-length frames from a TCP byte stream.

**AMQP 1.0 frame header layout (8 bytes):**
```
Bytes 0-3: SIZE  (uint32 big-endian) — total frame size including this header
Byte  4:   DOFF  (uint8)             — data offset in 4-byte words; min 2 (= 8 bytes, no extended header)
Byte  5:   TYPE  (uint8)             — 0x00 = AMQP performative frame, 0x01 = SASL frame
Bytes 6-7: CHANNEL (uint16 big-endian) — session channel (ignored/zero for SASL frames)
Bytes 8+:  Frame body (starts at byte DOFF*4)
```

A heartbeat is an AMQP frame with no body: SIZE=8, DOFF=2, TYPE=0, CHANNEL=0 → `"\x00\x00\x00\x08\x02\x00\x00\x00"`

### File map

- Create: `src/AMQP10/Protocol/FrameBuilder.php` — builds complete frames
- Create: `src/AMQP10/Protocol/FrameParser.php` — parses frames from a byte stream
- Create: `tests/Unit/Protocol/FrameBuilderTest.php`
- Create: `tests/Unit/Protocol/FrameParserTest.php`

---

### Task 3.1: FrameBuilder

**Files:**
- Create: `src/AMQP10/Protocol/FrameBuilder.php`
- Create: `tests/Unit/Protocol/FrameBuilderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Protocol/FrameBuilderTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\FrameBuilder;
use PHPUnit\Framework\TestCase;

class FrameBuilderTest extends TestCase
{
    // --- Invariants for all frames ---

    private function assertValidFrameHeader(string $frame): void
    {
        $this->assertGreaterThanOrEqual(8, strlen($frame), 'Frame must be at least 8 bytes');

        $size    = unpack('N', substr($frame, 0, 4))[1];
        $doff    = ord($frame[4]);
        $type    = ord($frame[5]);
        $channel = unpack('n', substr($frame, 6, 2))[1];

        $this->assertSame(strlen($frame), $size, 'SIZE field must equal total frame length');
        $this->assertGreaterThanOrEqual(2, $doff, 'DOFF must be >= 2 (8-byte minimum header)');
        $this->assertContains($type, [0x00, 0x01], 'TYPE must be 0x00 (AMQP) or 0x01 (SASL)');
    }

    // --- Heartbeat (empty AMQP frame) ---

    public function test_heartbeat_is_eight_bytes(): void
    {
        $frame = FrameBuilder::heartbeat();
        $this->assertSame(8, strlen($frame));
    }

    public function test_heartbeat_has_correct_header_bytes(): void
    {
        $frame = FrameBuilder::heartbeat();
        // SIZE=8, DOFF=2, TYPE=0x00 (AMQP), CHANNEL=0
        $this->assertSame("\x00\x00\x00\x08\x02\x00\x00\x00", $frame);
    }

    public function test_heartbeat_passes_invariants(): void
    {
        $this->assertValidFrameHeader(FrameBuilder::heartbeat());
    }

    // --- AMQP performative frame ---

    public function test_amqp_frame_with_body(): void
    {
        $body  = "\x00\x53\x10\x45"; // described(ulong(0x10), list0) — minimal OPEN-like body
        $frame = FrameBuilder::amqp(channel: 0, body: $body);

        $this->assertValidFrameHeader($frame);
        $this->assertSame(0x00, ord($frame[5]), 'TYPE must be 0x00 for AMQP frame');
        $this->assertSame(8 + strlen($body), strlen($frame));
        $this->assertSame($body, substr($frame, 8));
    }

    public function test_amqp_frame_channel_is_encoded(): void
    {
        $frame   = FrameBuilder::amqp(channel: 3, body: '');
        $channel = unpack('n', substr($frame, 6, 2))[1];
        $this->assertSame(3, $channel);
    }

    public function test_amqp_frame_size_reflects_body_length(): void
    {
        $body  = str_repeat("\x40", 100); // 100 nulls
        $frame = FrameBuilder::amqp(channel: 0, body: $body);
        $size  = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(108, $size); // 8 header + 100 body
    }

    // --- SASL frame ---

    public function test_sasl_frame_type_is_0x01(): void
    {
        $body  = "\x00\x53\x40\x45"; // minimal sasl-mechanisms body
        $frame = FrameBuilder::sasl(body: $body);

        $this->assertValidFrameHeader($frame);
        $this->assertSame(0x01, ord($frame[5]), 'TYPE must be 0x01 for SASL frame');
        $this->assertSame(0, unpack('n', substr($frame, 6, 2))[1], 'SASL channel bytes are zero');
    }

    // --- Protocol headers ---

    public function test_sasl_protocol_header(): void
    {
        // "AMQP" + protocol-id=3 + major=1 + minor=0 + revision=0
        $this->assertSame("AMQP\x03\x01\x00\x00", FrameBuilder::saslProtocolHeader());
    }

    public function test_amqp_protocol_header(): void
    {
        // "AMQP" + protocol-id=0 + major=1 + minor=0 + revision=0
        $this->assertSame("AMQP\x00\x01\x00\x00", FrameBuilder::amqpProtocolHeader());
    }

    public function test_protocol_headers_are_8_bytes(): void
    {
        $this->assertSame(8, strlen(FrameBuilder::saslProtocolHeader()));
        $this->assertSame(8, strlen(FrameBuilder::amqpProtocolHeader()));
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameBuilderTest.php
```

Expected: FAIL — `FrameBuilder` not found

- [ ] **Step 3: Implement FrameBuilder**

```php
<?php
// src/AMQP10/Protocol/FrameBuilder.php
namespace AMQP10\Protocol;

/**
 * Builds AMQP 1.0 frames.
 *
 * Frame header layout (spec §2.3):
 *   Bytes 0-3: SIZE  (uint32)  — total size including header
 *   Byte  4:   DOFF  (uint8)   — header size in 4-byte words (min 2 = 8 bytes)
 *   Byte  5:   TYPE  (uint8)   — 0x00=AMQP, 0x01=SASL
 *   Bytes 6-7: CHANNEL (uint16) — 0 for SASL/connection frames
 *   Bytes 8+:  Frame body (starts at DOFF*4)
 */
class FrameBuilder
{
    private const DOFF = 2;           // No extended header — body starts at byte 8
    private const TYPE_AMQP = 0x00;
    private const TYPE_SASL = 0x01;

    /**
     * An empty AMQP frame with no body — used as a heartbeat.
     * §2.4.6: "A frame with no body may be used as a heartbeat."
     */
    public static function heartbeat(): string
    {
        return self::buildHeader(size: 8, type: self::TYPE_AMQP, channel: 0);
    }

    /**
     * Build an AMQP performative frame (TYPE=0x00).
     *
     * @param int    $channel Session channel number (0 for connection-level)
     * @param string $body    Pre-encoded performative body (described AMQP type)
     */
    public static function amqp(int $channel, string $body): string
    {
        $size = 8 + strlen($body);
        return self::buildHeader(size: $size, type: self::TYPE_AMQP, channel: $channel) . $body;
    }

    /**
     * Build a SASL frame (TYPE=0x01).
     * Channel bytes are present but ignored for SASL frames (spec §5.3.8).
     *
     * @param string $body Pre-encoded SASL performative body
     */
    public static function sasl(string $body): string
    {
        $size = 8 + strlen($body);
        return self::buildHeader(size: $size, type: self::TYPE_SASL, channel: 0) . $body;
    }

    /**
     * The 8-byte protocol header sent before SASL negotiation.
     * Spec §5.3.2: "AMQP" + protocol-id=3 + major=1 + minor=0 + revision=0
     */
    public static function saslProtocolHeader(): string
    {
        return "AMQP\x03\x01\x00\x00";
    }

    /**
     * The 8-byte protocol header sent after SASL succeeds, to open the AMQP connection.
     * Spec §2.2: "AMQP" + protocol-id=0 + major=1 + minor=0 + revision=0
     */
    public static function amqpProtocolHeader(): string
    {
        return "AMQP\x00\x01\x00\x00";
    }

    private static function buildHeader(int $size, int $type, int $channel): string
    {
        return pack('N', $size)           // SIZE:    4 bytes uint32
             . pack('C', self::DOFF)      // DOFF:    1 byte  uint8
             . pack('C', $type)           // TYPE:    1 byte  uint8
             . pack('n', $channel);       // CHANNEL: 2 bytes uint16
    }
}
```

- [ ] **Step 4: Run to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameBuilderTest.php
```

Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Protocol/FrameBuilder.php tests/Unit/Protocol/FrameBuilderTest.php
git commit -m "feat: add FrameBuilder with correct 8-byte AMQP 1.0 frame headers"
```

---

### Task 3.2: FrameParser

The frame parser reads a raw byte stream and extracts complete frames. It must handle partial reads — TCP does not guarantee that a single `read()` returns exactly one complete frame.

**Files:**
- Create: `src/AMQP10/Protocol/FrameParser.php`
- Create: `tests/Unit/Protocol/FrameParserTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Protocol/FrameParserTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use PHPUnit\Framework\TestCase;

class FrameParserTest extends TestCase
{
    public function test_parse_single_complete_frame(): void
    {
        $frame  = FrameBuilder::heartbeat();
        $parser = new FrameParser();
        $parser->feed($frame);

        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_parse_frame_with_body(): void
    {
        $body   = "\x00\x53\x10\x45";
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed($frame);

        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_partial_header_produces_no_frames(): void
    {
        $frame  = FrameBuilder::heartbeat();
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 4)); // only first 4 bytes

        $this->assertEmpty($parser->readyFrames());
    }

    public function test_partial_body_produces_no_frames(): void
    {
        $body   = str_repeat("\x40", 20);
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 15)); // header + partial body

        $this->assertEmpty($parser->readyFrames());
    }

    public function test_frame_split_across_two_feeds(): void
    {
        $frame  = FrameBuilder::amqp(channel: 0, body: "\x00\x53\x10\x45");
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 6));
        $this->assertEmpty($parser->readyFrames());

        $parser->feed(substr($frame, 6));
        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_two_frames_in_one_feed(): void
    {
        $frame1 = FrameBuilder::heartbeat();
        $frame2 = FrameBuilder::amqp(channel: 0, body: "\x40");
        $parser = new FrameParser();
        $parser->feed($frame1 . $frame2);

        $frames = $parser->readyFrames();
        $this->assertCount(2, $frames);
        $this->assertSame($frame1, $frames[0]);
        $this->assertSame($frame2, $frames[1]);
    }

    public function test_ready_frames_clears_queue(): void
    {
        $parser = new FrameParser();
        $parser->feed(FrameBuilder::heartbeat());
        $parser->readyFrames(); // consume

        $this->assertEmpty($parser->readyFrames());
    }

    public function test_extract_body_from_frame(): void
    {
        $body   = "\x00\x53\x10\x45";
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();

        $this->assertSame($body, FrameParser::extractBody($frames[0]));
    }

    public function test_extract_channel_from_frame(): void
    {
        $frame  = FrameBuilder::amqp(channel: 7, body: '');
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();

        $this->assertSame(7, FrameParser::extractChannel($frames[0]));
    }

    public function test_extract_type_from_frame(): void
    {
        $amqpFrame = FrameBuilder::amqp(channel: 0, body: '');
        $saslFrame = FrameBuilder::sasl(body: "\x40");

        $parser = new FrameParser();
        $parser->feed($amqpFrame);
        $parser->feed($saslFrame);
        $frames = $parser->readyFrames();

        $this->assertSame(0x00, FrameParser::extractType($frames[0]));
        $this->assertSame(0x01, FrameParser::extractType($frames[1]));
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameParserTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement FrameParser**

```php
<?php
// src/AMQP10/Protocol/FrameParser.php
namespace AMQP10\Protocol;

/**
 * Parses AMQP 1.0 frames from a raw byte stream.
 *
 * Usage:
 *   $parser = new FrameParser();
 *   $parser->feed($bytesFromSocket);
 *   foreach ($parser->readyFrames() as $frame) {
 *       $type    = FrameParser::extractType($frame);
 *       $channel = FrameParser::extractChannel($frame);
 *       $body    = FrameParser::extractBody($frame);
 *   }
 */
class FrameParser
{
    private string $buffer = '';
    /** @var string[] */
    private array $ready = [];

    /**
     * Feed raw bytes from the transport into the parser.
     * Call readyFrames() after each feed.
     */
    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
        $this->parse();
    }

    /**
     * Return all complete frames available since last call.
     * Clears the internal queue.
     *
     * @return string[]
     */
    public function readyFrames(): array
    {
        $frames      = $this->ready;
        $this->ready = [];
        return $frames;
    }

    // --- Static helpers for inspecting a complete frame ---

    /** Extract the TYPE byte (0x00 AMQP, 0x01 SASL) */
    public static function extractType(string $frame): int
    {
        return ord($frame[5]);
    }

    /** Extract the CHANNEL number (uint16) */
    public static function extractChannel(string $frame): int
    {
        return unpack('n', substr($frame, 6, 2))[1];
    }

    /**
     * Extract the frame body (everything after the fixed header).
     * Body starts at byte DOFF*4 (DOFF is at byte 4).
     */
    public static function extractBody(string $frame): string
    {
        $doff = ord($frame[4]);
        return substr($frame, $doff * 4);
    }

    // --- Internal ---

    private function parse(): void
    {
        while (strlen($this->buffer) >= 4) {
            $size = unpack('N', substr($this->buffer, 0, 4))[1];

            if (strlen($this->buffer) < $size) {
                break; // incomplete frame — wait for more data
            }

            $this->ready[] = substr($this->buffer, 0, $size);
            $this->buffer  = substr($this->buffer, $size);
        }
    }
}
```

- [ ] **Step 4: Run to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameParserTest.php
```

Expected: All pass

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Protocol/FrameParser.php tests/Unit/Protocol/FrameParserTest.php
git commit -m "feat: add FrameParser with stream-safe TCP frame reassembly"
```

---

## Chunk 4: Protocol Layer — Performatives and SASL

Performatives are the commands of AMQP 1.0. Each is a described AMQP composite list with a specific ulong descriptor. The original plan had the wrong descriptor code for the frame type byte (confused 0x02 with the PERFORMATIVE constant). This chunk implements the encoding and decoding of all nine AMQP performatives plus the four SASL performatives.

**Performative descriptor codes (ulong):**

| Performative | Descriptor | Level      |
|-------------|------------|------------|
| open        | 0x10       | Connection |
| begin       | 0x11       | Session    |
| attach      | 0x12       | Link       |
| flow        | 0x13       | Link       |
| transfer    | 0x14       | Link       |
| disposition | 0x15       | Link       |
| detach      | 0x16       | Link       |
| end         | 0x17       | Session    |
| close       | 0x18       | Connection |

**SASL performative descriptor codes:**

| Performative     | Descriptor |
|-----------------|------------|
| sasl-mechanisms  | 0x40       |
| sasl-init        | 0x41       |
| sasl-challenge   | 0x42       |
| sasl-response    | 0x43       |
| sasl-outcome     | 0x44       |

**Outcome descriptor codes (used in Disposition frames):**

| Outcome   | Descriptor |
|-----------|------------|
| accepted  | 0x24       |
| rejected  | 0x25       |
| released  | 0x26       |
| modified  | 0x27       |

### File map

- Create: `src/AMQP10/Protocol/Descriptor.php` — all descriptor constants
- Create: `src/AMQP10/Protocol/PerformativeEncoder.php` — builds complete frames for each performative
- Create: `src/AMQP10/Protocol/PerformativeDecoder.php` — decodes frame bodies to typed arrays
- Create: `tests/Unit/Protocol/DescriptorTest.php`
- Create: `tests/Unit/Protocol/PerformativeEncoderTest.php`
- Create: `tests/Unit/Protocol/PerformativeDecoderTest.php`

---

### Task 4.1: Descriptor Constants

**Files:**
- Create: `src/AMQP10/Protocol/Descriptor.php`

- [ ] **Step 1: Create Descriptor**

```php
<?php
// src/AMQP10/Protocol/Descriptor.php
namespace AMQP10\Protocol;

/**
 * AMQP 1.0 performative and section descriptor codes.
 * These are ulong values used as described-type descriptors.
 *
 * Spec: Transport §2.7, Security §5.3, Messaging §3.2
 */
final class Descriptor
{
    // Connection-level performatives
    public const OPEN  = 0x10;
    public const CLOSE = 0x18;

    // Session-level performatives
    public const BEGIN = 0x11;
    public const END   = 0x17;

    // Link-level performatives
    public const ATTACH      = 0x12;
    public const FLOW        = 0x13;
    public const TRANSFER    = 0x14;
    public const DISPOSITION = 0x15;
    public const DETACH      = 0x16;

    // SASL performatives (TYPE=0x01 frames)
    public const SASL_MECHANISMS = 0x40;
    public const SASL_INIT       = 0x41;
    public const SASL_CHALLENGE  = 0x42;
    public const SASL_RESPONSE   = 0x43;
    public const SASL_OUTCOME    = 0x44;

    // Delivery outcomes (used inside Disposition frames)
    public const ACCEPTED = 0x24;
    public const REJECTED = 0x25;
    public const RELEASED = 0x26;
    public const MODIFIED = 0x27;

    // Message sections (used in Transfer frame bodies)
    public const MSG_HEADER              = 0x70;
    public const MSG_DELIVERY_ANNOTATIONS = 0x71;
    public const MSG_ANNOTATIONS        = 0x72;
    public const MSG_PROPERTIES         = 0x73;
    public const MSG_APPLICATION_PROPS  = 0x74;
    public const MSG_DATA               = 0x75;
    public const MSG_AMQP_SEQUENCE      = 0x76;
    public const MSG_AMQP_VALUE         = 0x77;
    public const MSG_FOOTER             = 0x78;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/AMQP10/Protocol/Descriptor.php
git commit -m "feat: add AMQP 1.0 performative and section descriptor constants"
```

---

### Task 4.2: PerformativeEncoder

Each performative is encoded as: `FrameBuilder::amqp/sasl(channel, body)` where body is `TypeEncoder::encodeDescribed(ulong(descriptor), list([...fields...]))`.

Null fields at the end of a list can be omitted. Null fields in the middle must be encoded as `TypeCode::NULL`.

**Files:**
- Create: `src/AMQP10/Protocol/PerformativeEncoder.php`
- Create: `tests/Unit/Protocol/PerformativeEncoderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Protocol/PerformativeEncoderTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\Descriptor;
use PHPUnit\Framework\TestCase;

class PerformativeEncoderTest extends TestCase
{
    // Helper: decode the first described type from a frame body
    private function decodePerformative(string $frame): array
    {
        $body    = FrameParser::extractBody($frame);
        $decoder = new TypeDecoder($body);
        return $decoder->decode(); // returns ['descriptor' => int, 'value' => array]
    }

    // --- Frame structure invariants ---

    private function assertAmqpFrame(string $frame, int $expectedDescriptor, int $channel = 0): void
    {
        $this->assertSame(0x00, FrameParser::extractType($frame), 'Must be AMQP frame (TYPE=0x00)');
        $this->assertSame($channel, FrameParser::extractChannel($frame));

        $size = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(strlen($frame), $size, 'SIZE must equal total frame length');

        $performative = $this->decodePerformative($frame);
        $this->assertSame($expectedDescriptor, $performative['descriptor']);
    }

    private function assertSaslFrame(string $frame, int $expectedDescriptor): void
    {
        $this->assertSame(0x01, FrameParser::extractType($frame), 'Must be SASL frame (TYPE=0x01)');
        $size = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(strlen($frame), $size);

        $performative = $this->decodePerformative($frame);
        $this->assertSame($expectedDescriptor, $performative['descriptor']);
    }

    // --- OPEN ---

    public function test_encode_open_is_amqp_frame_with_open_descriptor(): void
    {
        $frame = PerformativeEncoder::open(containerId: 'client-1', hostname: 'localhost');
        $this->assertAmqpFrame($frame, Descriptor::OPEN);
    }

    public function test_open_contains_container_id(): void
    {
        $frame        = PerformativeEncoder::open(containerId: 'my-client');
        $performative = $this->decodePerformative($frame);
        // OPEN fields: [container-id, hostname, max-frame-size, channel-max, idle-time-out, ...]
        $this->assertSame('my-client', $performative['value'][0]);
    }

    public function test_open_contains_hostname(): void
    {
        $frame        = PerformativeEncoder::open(containerId: 'c', hostname: 'vhost:tenant-1');
        $performative = $this->decodePerformative($frame);
        $this->assertSame('vhost:tenant-1', $performative['value'][1]);
    }

    // --- BEGIN ---

    public function test_encode_begin_is_amqp_frame_with_begin_descriptor(): void
    {
        $frame = PerformativeEncoder::begin(channel: 0);
        $this->assertAmqpFrame($frame, Descriptor::BEGIN, channel: 0);
    }

    public function test_begin_fields_are_present(): void
    {
        $frame        = PerformativeEncoder::begin(channel: 0, nextOutgoingId: 0, incomingWindow: 256, outgoingWindow: 256);
        $performative = $this->decodePerformative($frame);
        $fields       = $performative['value'];
        // BEGIN fields: [remote-channel, next-outgoing-id, incoming-window, outgoing-window, ...]
        // remote-channel is null for locally-initiated sessions
        $this->assertNull($fields[0]);
        $this->assertSame(0, $fields[1]);   // next-outgoing-id
        $this->assertSame(256, $fields[2]); // incoming-window
        $this->assertSame(256, $fields[3]); // outgoing-window
    }

    // --- ATTACH ---

    public function test_encode_attach_is_amqp_frame_with_attach_descriptor(): void
    {
        $frame = PerformativeEncoder::attach(
            channel: 0,
            name: 'my-link',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            target: '/exchanges/my-exchange/key',
        );
        $this->assertAmqpFrame($frame, Descriptor::ATTACH);
    }

    public function test_attach_role_sender_is_false(): void
    {
        // AMQP 1.0 spec: role=false means sender, role=true means receiver
        $frame        = PerformativeEncoder::attach(channel: 0, name: 'l', handle: 0, role: PerformativeEncoder::ROLE_SENDER, target: '/q/test');
        $performative = $this->decodePerformative($frame);
        $this->assertFalse($performative['value'][2]); // role field (index 2)
    }

    public function test_attach_role_receiver_is_true(): void
    {
        $frame        = PerformativeEncoder::attach(channel: 0, name: 'l', handle: 0, role: PerformativeEncoder::ROLE_RECEIVER, source: '/q/test');
        $performative = $this->decodePerformative($frame);
        $this->assertTrue($performative['value'][2]); // role field
    }

    // --- CLOSE ---

    public function test_encode_close_is_amqp_frame_with_close_descriptor(): void
    {
        $frame = PerformativeEncoder::close(channel: 0);
        $this->assertAmqpFrame($frame, Descriptor::CLOSE);
    }

    // --- SASL performatives ---

    public function test_encode_sasl_mechanisms_is_sasl_frame(): void
    {
        $frame = PerformativeEncoder::saslMechanisms(['PLAIN']);
        $this->assertSaslFrame($frame, Descriptor::SASL_MECHANISMS);
    }

    public function test_sasl_mechanisms_contains_mechanism_list(): void
    {
        $frame        = PerformativeEncoder::saslMechanisms(['PLAIN', 'EXTERNAL']);
        $performative = $this->decodePerformative($frame);
        // mechanisms field is a symbol array — decoded as array of strings
        $mechanisms = $performative['value'][0];
        $this->assertContains('PLAIN', $mechanisms);
        $this->assertContains('EXTERNAL', $mechanisms);
    }

    public function test_encode_sasl_init_is_sasl_frame(): void
    {
        $frame = PerformativeEncoder::saslInit(mechanism: 'PLAIN', initialResponse: "\x00guest\x00guest");
        $this->assertSaslFrame($frame, Descriptor::SASL_INIT);
    }

    public function test_sasl_init_fields(): void
    {
        $response     = "\x00user\x00pass";
        $frame        = PerformativeEncoder::saslInit('PLAIN', $response);
        $performative = $this->decodePerformative($frame);
        // sasl-init fields: [mechanism, initial-response, hostname]
        $this->assertSame('PLAIN', $performative['value'][0]);
        $this->assertSame($response, $performative['value'][1]);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/PerformativeEncoderTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement PerformativeEncoder**

```php
<?php
// src/AMQP10/Protocol/PerformativeEncoder.php
namespace AMQP10\Protocol;

/**
 * Encodes AMQP 1.0 performatives as complete frames.
 *
 * Each performative is a described type: \x00 + ulong(descriptor) + list(fields)
 * Trailing null fields may be omitted. Mid-list nulls must be included.
 *
 * Spec: Transport §2.7, Security §5.3
 */
class PerformativeEncoder
{
    public const ROLE_SENDER   = false;
    public const ROLE_RECEIVER = true;

    // Sender settle modes
    public const SND_UNSETTLED = 0; // sender sends unsettled, awaits disposition
    public const SND_SETTLED   = 1; // sender settles immediately (fire-and-forget)
    public const SND_MIXED     = 2; // per-message decision

    // Receiver settle modes
    public const RCV_FIRST  = 0; // receiver settles first
    public const RCV_SECOND = 1; // receiver waits for sender to settle

    // --- Connection level ---

    /**
     * OPEN performative (descriptor 0x10, channel 0).
     *
     * Fields: container-id, hostname, max-frame-size, channel-max, idle-time-out
     *
     * @param string      $containerId  Unique identifier for this AMQP container
     * @param string|null $hostname     Used by RabbitMQ for vhost routing, e.g. "vhost:tenant-1"
     * @param int         $maxFrameSize Maximum frame size we can accept (0 = no limit)
     * @param int         $channelMax   Maximum channel number
     * @param int         $idleTimeOut  Milliseconds; 0 = no timeout
     */
    public static function open(
        string  $containerId,
        ?string $hostname     = null,
        int     $maxFrameSize = 65536,
        int     $channelMax   = 65535,
        int     $idleTimeOut  = 60000,
    ): string {
        $fields = [
            TypeEncoder::encodeString($containerId),
            $hostname !== null ? TypeEncoder::encodeString($hostname) : TypeEncoder::encodeNull(),
            TypeEncoder::encodeUint($maxFrameSize),
            TypeEncoder::encodeUshort($channelMax),
            TypeEncoder::encodeUint($idleTimeOut),
        ];
        return FrameBuilder::amqp(channel: 0, body: self::described(Descriptor::OPEN, $fields));
    }

    /**
     * CLOSE performative (descriptor 0x18, channel 0).
     * Fields: error (optional)
     */
    public static function close(int $channel, ?string $errorDescription = null): string
    {
        $fields = $errorDescription !== null ? [TypeEncoder::encodeString($errorDescription)] : [];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::CLOSE, $fields));
    }

    // --- Session level ---

    /**
     * BEGIN performative (descriptor 0x11).
     *
     * Fields: remote-channel, next-outgoing-id, incoming-window, outgoing-window, handle-max
     *
     * remote-channel is null for locally-initiated sessions; set for remotely-initiated ones.
     */
    public static function begin(
        int  $channel,
        int  $nextOutgoingId  = 0,
        int  $incomingWindow  = 2048,
        int  $outgoingWindow  = 2048,
        int  $handleMax       = 255,
        ?int $remoteChannel   = null,
    ): string {
        $fields = [
            $remoteChannel !== null ? TypeEncoder::encodeUshort($remoteChannel) : TypeEncoder::encodeNull(),
            TypeEncoder::encodeUint($nextOutgoingId),
            TypeEncoder::encodeUint($incomingWindow),
            TypeEncoder::encodeUint($outgoingWindow),
            TypeEncoder::encodeUint($handleMax),
        ];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::BEGIN, $fields));
    }

    /**
     * END performative (descriptor 0x17).
     */
    public static function end(int $channel): string
    {
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::END, []));
    }

    // --- Link level ---

    /**
     * ATTACH performative (descriptor 0x12).
     *
     * Fields: name, handle, role, snd-settle-mode, rcv-settle-mode, source, target
     *
     * role: false = sender, true = receiver (spec §2.6.3)
     * For a sender link (publishing), set target address.
     * For a receiver link (consuming), set source address.
     *
     * Source and target are encoded as described types with their own structure.
     * For simplicity, encode them as a list with just the address string as first field.
     */
    public static function attach(
        int     $channel,
        string  $name,
        int     $handle,
        bool    $role,
        ?string $source          = null,
        ?string $target          = null,
        int     $sndSettleMode   = self::SND_UNSETTLED,
        int     $rcvSettleMode   = self::RCV_FIRST,
        ?array  $properties      = null,
    ): string {
        $fields = [
            TypeEncoder::encodeString($name),
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeBool($role),
            TypeEncoder::encodeUbyte($sndSettleMode),
            TypeEncoder::encodeUbyte($rcvSettleMode),
            self::encodeTerminus($source),   // source terminus
            self::encodeTerminus($target),   // target terminus
        ];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::ATTACH, $fields));
    }

    /**
     * DETACH performative (descriptor 0x16).
     */
    public static function detach(int $channel, int $handle, bool $closed = true): string
    {
        $fields = [
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeBool($closed),
        ];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::DETACH, $fields));
    }

    /**
     * FLOW performative (descriptor 0x13).
     *
     * Fields: next-incoming-id, incoming-window, next-outgoing-id, outgoing-window,
     *         handle, delivery-count, link-credit, available, drain, echo
     */
    public static function flow(
        int $channel,
        int $nextIncomingId,
        int $incomingWindow,
        int $nextOutgoingId,
        int $outgoingWindow,
        int $handle,
        int $deliveryCount,
        int $linkCredit,
    ): string {
        $fields = [
            TypeEncoder::encodeUint($nextIncomingId),
            TypeEncoder::encodeUint($incomingWindow),
            TypeEncoder::encodeUint($nextOutgoingId),
            TypeEncoder::encodeUint($outgoingWindow),
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeUint($deliveryCount),
            TypeEncoder::encodeUint($linkCredit),
        ];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::FLOW, $fields));
    }

    /**
     * TRANSFER performative (descriptor 0x14).
     *
     * Fields: handle, delivery-id, delivery-tag, message-format, settled, more
     *
     * The message payload is appended after the performative in the same frame body.
     */
    public static function transfer(
        int    $channel,
        int    $handle,
        int    $deliveryId,
        string $deliveryTag,
        string $messagePayload,
        bool   $settled       = false,
        int    $messageFormat = 0,
    ): string {
        $fields = [
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeUint($deliveryId),
            TypeEncoder::encodeBinary($deliveryTag),
            TypeEncoder::encodeUint($messageFormat),
            TypeEncoder::encodeBool($settled),
            TypeEncoder::encodeBool(false), // more = false (single-frame messages)
        ];
        $body = self::described(Descriptor::TRANSFER, $fields) . $messagePayload;
        return FrameBuilder::amqp(channel: $channel, body: $body);
    }

    /**
     * DISPOSITION performative (descriptor 0x15).
     *
     * Fields: role, first, last, settled, state
     * Used to acknowledge message delivery (send outcome: accepted/rejected/released).
     *
     * role: true = receiver (consumer sending ack), false = sender (publisher receiving outcome)
     */
    public static function disposition(
        int    $channel,
        bool   $role,
        int    $first,
        ?int   $last     = null,
        bool   $settled  = true,
        string $state    = '', // pre-encoded outcome (accepted/rejected/released)
    ): string {
        $fields = [
            TypeEncoder::encodeBool($role),
            TypeEncoder::encodeUint($first),
            $last !== null ? TypeEncoder::encodeUint($last) : TypeEncoder::encodeNull(),
            TypeEncoder::encodeBool($settled),
            $state !== '' ? $state : TypeEncoder::encodeNull(),
        ];
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::DISPOSITION, $fields));
    }

    /**
     * Encode the Accepted outcome (for use as the $state in disposition()).
     * Accepted is an empty described list with descriptor 0x24.
     */
    public static function accepted(): string
    {
        return self::described(Descriptor::ACCEPTED, []);
    }

    public static function rejected(): string
    {
        return self::described(Descriptor::REJECTED, []);
    }

    public static function released(): string
    {
        return self::described(Descriptor::RELEASED, []);
    }

    // --- SASL performatives ---

    /**
     * sasl-mechanisms (descriptor 0x40) — sent by server.
     * Fields: mechanisms (symbol array)
     *
     * @param string[] $mechanisms e.g. ['PLAIN', 'EXTERNAL']
     */
    public static function saslMechanisms(array $mechanisms): string
    {
        $fields = [TypeEncoder::encodeSymbolArray($mechanisms)];
        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_MECHANISMS, $fields));
    }

    /**
     * sasl-init (descriptor 0x41) — sent by client.
     * Fields: mechanism (symbol), initial-response (binary), hostname (string, optional)
     *
     * For PLAIN: initial-response = "\x00" . $username . "\x00" . $password
     */
    public static function saslInit(string $mechanism, string $initialResponse, ?string $hostname = null): string
    {
        $fields = [
            TypeEncoder::encodeSymbol($mechanism),
            TypeEncoder::encodeBinary($initialResponse),
            $hostname !== null ? TypeEncoder::encodeString($hostname) : TypeEncoder::encodeNull(),
        ];
        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_INIT, $fields));
    }

    /**
     * sasl-outcome (descriptor 0x44) — sent by server.
     * Fields: code (ubyte: 0=ok, 1=auth, 2=sys, 3=sys-perm, 4=sys-temp)
     */
    public static function saslOutcome(int $code): string
    {
        $fields = [TypeEncoder::encodeUbyte($code)];
        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_OUTCOME, $fields));
    }

    // --- Helpers ---

    /**
     * Encode a performative as a described list.
     *
     * @param string[] $fields Pre-encoded AMQP field values
     */
    private static function described(int $descriptor, array $fields): string
    {
        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong($descriptor),
            TypeEncoder::encodeList($fields),
        );
    }

    /**
     * Encode a source or target terminus.
     *
     * In AMQP 1.0 these are described composite types with their own structure.
     * For RabbitMQ: the address is the first field. Other fields are optional.
     *
     * Source descriptor: 0x28, Target descriptor: 0x29
     */
    private static function encodeTerminus(?string $address): string
    {
        if ($address === null) {
            return TypeEncoder::encodeNull();
        }
        // Simplified terminus: described list with just address
        // A real implementation needs all terminus fields (durable, expiry-policy, etc.)
        $fields = [TypeEncoder::encodeString($address)];
        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(0x28), // source descriptor (reused for both; differentiated by position)
            TypeEncoder::encodeList($fields),
        );
    }
}
```

> **Note on terminus encoding:** Source (descriptor 0x28) and target (descriptor 0x29) are described composite types. The implementation above uses 0x28 for both as a simplification. A production implementation should use 0x28 for source and 0x29 for target, and include the full terminus field list (durable, expiry-policy, timeout, dynamic, dynamic-node-properties, filter, default-outcome, outcomes, capabilities).

- [ ] **Step 4: Run to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/PerformativeEncoderTest.php
```

Expected: All pass

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Protocol/Descriptor.php src/AMQP10/Protocol/PerformativeEncoder.php tests/Unit/Protocol/
git commit -m "feat: add performative encoder for all AMQP 1.0 and SASL performatives"
```

---

## Chunk 5: Transport Layer

The transport layer handles raw bytes over TCP. The original plan had two bugs: wrong PHP type (`\Socket` vs stream resource) and wrong `stream_socket_client()` signature.

### File map

- Create: `src/AMQP10/Transport/TransportInterface.php`
- Create: `src/AMQP10/Transport/BlockingAdapter.php`
- Create: `tests/Unit/Transport/TransportInterfaceTest.php`
- Create: `tests/Unit/Transport/BlockingAdapterTest.php`

---

### Task 5.1: TransportInterface and BlockingAdapter

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Transport/TransportInterfaceTest.php
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class TransportInterfaceTest extends TestCase
{
    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists(TransportInterface::class));
    }
}
```

```php
<?php
// tests/Unit/Transport/BlockingAdapterTest.php
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\BlockingAdapter;
use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class BlockingAdapterTest extends TestCase
{
    public function test_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, new BlockingAdapter());
    }

    public function test_is_not_connected_initially(): void
    {
        $adapter = new BlockingAdapter();
        $this->assertFalse($adapter->isConnected());
    }

    public function test_connect_to_invalid_host_throws(): void
    {
        $this->expectException(\AMQP10\Exception\ConnectionFailedException::class);
        $adapter = new BlockingAdapter();
        $adapter->connect('amqp://localhost:1'); // port 1 should always refuse
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Transport/
```

Expected: FAIL

- [ ] **Step 3: Implement TransportInterface**

```php
<?php
// src/AMQP10/Transport/TransportInterface.php
namespace AMQP10\Transport;

interface TransportInterface
{
    /**
     * Open a TCP connection to the given AMQP URI.
     * For TLS, use amqps:// scheme (RabbitMQ runs pure TLS — no AMQP TLS header).
     *
     * @throws \AMQP10\Exception\ConnectionFailedException
     */
    public function connect(string $uri): void;

    public function disconnect(): void;

    /**
     * Send raw bytes (a complete frame or protocol header).
     *
     * @throws \AMQP10\Exception\ConnectionFailedException if not connected
     */
    public function send(string $bytes): void;

    /**
     * Read up to $length bytes from the socket. Returns empty string if no data yet.
     * Returns null if connection closed by peer.
     *
     * @throws \AMQP10\Exception\ConnectionFailedException if not connected
     */
    public function read(int $length = 4096): ?string;

    public function isConnected(): bool;
}
```

> **Note:** The interface uses `send()`/`read()` (raw bytes), not `sendFrame()`/`receiveFrame()`. Frame assembly is done by `FrameParser` above the transport layer. This keeps the transport dumb and the frame logic testable.

- [ ] **Step 4: Implement BlockingAdapter**

```php
<?php
// src/AMQP10/Transport/BlockingAdapter.php
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;

class BlockingAdapter implements TransportInterface
{
    /** @var resource|null */
    private mixed $stream = null;

    public function connect(string $uri): void
    {
        $parts = parse_url($uri);
        $host  = $parts['host'] ?? 'localhost';
        $port  = $parts['port'] ?? 5672;
        $tls   = ($parts['scheme'] ?? 'amqp') === 'amqps';

        // RabbitMQ runs a pure TLS server (spec §5.2.1) — just use ssl:// transport
        $address = ($tls ? 'ssl' : 'tcp') . "://$host:$port";

        $stream = @stream_socket_client($address, $errno, $errstr, timeout: 10);

        if ($stream === false) {
            throw new ConnectionFailedException("Cannot connect to $address: $errstr (errno $errno)");
        }

        stream_set_blocking($stream, false); // non-blocking reads (poll)
        $this->stream = $stream;
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function send(string $bytes): void
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }
        fwrite($this->stream, $bytes);
    }

    public function read(int $length = 4096): ?string
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }

        $data = fread($this->stream, $length);

        if ($data === false || ($data === '' && feof($this->stream))) {
            return null; // connection closed
        }

        return $data;
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Transport/
```

Expected: Interface test passes; BlockingAdapter connect-to-invalid-host passes; isConnected test passes.

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Transport/ tests/Unit/Transport/
git commit -m "feat: add TransportInterface and BlockingAdapter with correct stream_socket_client usage"
```

---

## Chunk 6: Connection Layer

The Connection class manages the full AMQP 1.0 connection lifecycle:

1. TCP connect (via transport)
2. SASL negotiation: send SASL header → receive mechanisms → send init → receive outcome
3. AMQP open: send AMQP header → send OPEN → receive OPEN (negotiate parameters)
4. Close: send CLOSE → receive CLOSE → TCP disconnect

The original plan sent a 7-byte header with the wrong protocol-id. This chunk fixes that.

### File map

- Create: `src/AMQP10/Connection/Sasl.php` — SASL credential config (fixes test inconsistency)
- Create: `src/AMQP10/Connection/Connection.php` — manages connection lifecycle
- Create: `src/AMQP10/Connection/AutoReconnect.php` — retry with backoff
- Create: `tests/Mocks/TransportMock.php` — needed for connection tests
- Create: `tests/Unit/Connection/SaslTest.php`
- Create: `tests/Unit/Connection/ConnectionTest.php`
- Create: `tests/Unit/Connection/AutoReconnectTest.php`

---

### Task 6.1: TransportMock

The mock simulates a connected transport without a real socket. It records sent bytes and lets tests inject bytes to be received.

**Files:**
- Create: `tests/Mocks/TransportMock.php`

- [ ] **Step 1: Create TransportMock**

```php
<?php
// tests/Mocks/TransportMock.php
namespace AMQP10\Tests\Mocks;

use AMQP10\Transport\TransportInterface;

/**
 * A mock transport for unit tests.
 *
 * Usage:
 *   $mock = new TransportMock();
 *   $mock->queueIncoming($bytes);  // bytes the "server" will send
 *   // ... code under test calls $mock->read() and $mock->send()
 *   $sent = $mock->sent();         // all bytes the client sent
 */
class TransportMock implements TransportInterface
{
    private bool   $connected = false;
    private string $incoming  = ''; // bytes to serve via read()
    private string $outgoing  = ''; // bytes the code under test sent()

    public function connect(string $uri): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function send(string $bytes): void
    {
        $this->outgoing .= $bytes;
    }

    public function read(int $length = 4096): ?string
    {
        if (!$this->connected || $this->incoming === '') {
            return '';
        }
        $chunk          = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);
        return $chunk;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** Queue bytes for read() to return (simulates server sending data) */
    public function queueIncoming(string $bytes): void
    {
        $this->incoming .= $bytes;
    }

    /** All bytes sent by the code under test */
    public function sent(): string
    {
        return $this->outgoing;
    }

    /** Reset sent log (useful in multi-step tests) */
    public function clearSent(): void
    {
        $this->outgoing = '';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Mocks/TransportMock.php
git commit -m "test: add TransportMock for unit testing without real sockets"
```

---

### Task 6.2: Sasl class

The original plan had a test/implementation mismatch on the PLAIN initial response format. The correct format per RFC 4616 is `"\x00" . authcid . "\x00" . passwd` (empty authzid).

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Connection/SaslTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Sasl;
use PHPUnit\Framework\TestCase;

class SaslTest extends TestCase
{
    public function test_plain_mechanism_name(): void
    {
        $sasl = Sasl::plain('user', 'pass');
        $this->assertSame('PLAIN', $sasl->mechanism());
    }

    public function test_plain_initial_response_format(): void
    {
        // RFC 4616 PLAIN format: [authzid] NUL authcid NUL passwd
        // With empty authzid: "\x00" + username + "\x00" + password
        $sasl = Sasl::plain('user', 'pass');
        $this->assertSame("\x00user\x00pass", $sasl->initialResponse());
    }

    public function test_plain_from_uri_credentials(): void
    {
        $sasl = Sasl::plain('guest', 'guest');
        $this->assertSame("\x00guest\x00guest", $sasl->initialResponse());
    }

    public function test_external_mechanism(): void
    {
        $sasl = Sasl::external();
        $this->assertSame('EXTERNAL', $sasl->mechanism());
        $this->assertSame('', $sasl->initialResponse());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SaslTest.php
```

- [ ] **Step 3: Implement Sasl**

```php
<?php
// src/AMQP10/Connection/Sasl.php
namespace AMQP10\Connection;

readonly class Sasl
{
    private function __construct(
        private string $mechanism,
        private string $initialResponse,
    ) {}

    public static function plain(string $username, string $password): self
    {
        // RFC 4616: [authzid] NUL authcid NUL passwd — empty authzid
        return new self('PLAIN', "\x00$username\x00$password");
    }

    public static function external(): self
    {
        return new self('EXTERNAL', '');
    }

    public function mechanism(): string
    {
        return $this->mechanism;
    }

    public function initialResponse(): string
    {
        return $this->initialResponse;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SaslTest.php
```

Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/Sasl.php tests/Unit/Connection/SaslTest.php
git commit -m "feat: add Sasl class with correct RFC 4616 PLAIN format"
```

---

### Task 6.3: Connection

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Connection/ConnectionTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private function makeMockWithSaslHandshake(Sasl $sasl): TransportMock
    {
        $mock = new TransportMock();

        // Queue what the server sends during SASL negotiation:
        $mock->queueIncoming(FrameBuilder::saslProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::saslMechanisms([$sasl->mechanism()]));
        $mock->queueIncoming(PerformativeEncoder::saslOutcome(0)); // code 0 = ok

        // Queue what the server sends for AMQP open:
        $mock->queueIncoming(FrameBuilder::amqpProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::open(containerId: 'server', hostname: 'localhost'));

        return $mock;
    }

    public function test_is_not_open_initially(): void
    {
        $mock       = new TransportMock();
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/');
        $this->assertFalse($connection->isOpen());
    }

    public function test_open_sends_sasl_protocol_header_first(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);

        $connection->open();

        // First 8 bytes sent must be the SASL protocol header
        $sent = $mock->sent();
        $this->assertSame(FrameBuilder::saslProtocolHeader(), substr($sent, 0, 8));
    }

    public function test_open_sends_amqp_protocol_header_after_sasl(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);

        $connection->open();

        // Find the AMQP header in sent bytes
        $sent = $mock->sent();
        $this->assertStringContainsString(FrameBuilder::amqpProtocolHeader(), $sent);
    }

    public function test_connection_is_open_after_successful_open(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);

        $connection->open();

        $this->assertTrue($connection->isOpen());
    }

    public function test_sasl_failure_throws_authentication_exception(): void
    {
        $mock = new TransportMock();
        $mock->queueIncoming(FrameBuilder::saslProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::saslMechanisms(['PLAIN']));
        $mock->queueIncoming(PerformativeEncoder::saslOutcome(1)); // code 1 = auth failure

        $sasl       = Sasl::plain('wrong', 'creds');
        $connection = new Connection($mock, 'amqp://wrong:creds@localhost:5672/', $sasl);

        $this->expectException(\AMQP10\Exception\AuthenticationException::class);
        $connection->open();
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Connection/ConnectionTest.php
```

- [ ] **Step 3: Implement Connection**

```php
<?php
// src/AMQP10/Connection/Connection.php
namespace AMQP10\Connection;

use AMQP10\Client\Config;
use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Transport\TransportInterface;

class Connection
{
    private bool $open = false;
    private int  $negotiatedMaxFrameSize = 65536;
    private readonly string $containerId;
    private readonly FrameParser $parser;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $uri,
        private readonly ?Sasl $sasl = null,
    ) {
        $this->containerId = 'php-amqp10-' . bin2hex(random_bytes(4));
        $this->parser      = new FrameParser();
    }

    public function open(): void
    {
        $parts = parse_url($this->uri);
        $this->transport->connect($this->uri);

        $sasl = $this->sasl ?? Sasl::plain(
            $parts['user'] ?? 'guest',
            $parts['pass'] ?? 'guest',
        );

        $this->saslHandshake($sasl);
        $this->amqpOpen($parts['host'] ?? 'localhost');

        $this->open = true;
    }

    public function close(): void
    {
        if ($this->open) {
            $this->transport->send(PerformativeEncoder::close(channel: 0));
            $this->transport->disconnect();
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function negotiatedMaxFrameSize(): int
    {
        return $this->negotiatedMaxFrameSize;
    }

    // --- SASL negotiation (spec §5.3) ---

    private function saslHandshake(Sasl $sasl): void
    {
        // Step 1: Exchange SASL protocol headers
        $this->transport->send(FrameBuilder::saslProtocolHeader());
        $this->expectProtocolHeader(FrameBuilder::saslProtocolHeader());

        // Step 2: Server sends sasl-mechanisms; read it
        $mechanismsFrame = $this->readSaslFrame();
        // (We trust the server's mechanisms list; validation left to production impl)

        // Step 3: Client sends sasl-init
        $this->transport->send(
            PerformativeEncoder::saslInit($sasl->mechanism(), $sasl->initialResponse())
        );

        // Step 4: Server sends sasl-outcome
        $outcomeFrame = $this->readSaslFrame();
        $body         = FrameParser::extractBody($outcomeFrame);
        $performative = (new TypeDecoder($body))->decode();

        if ($performative['descriptor'] !== Descriptor::SASL_OUTCOME) {
            throw new ConnectionFailedException('Expected sasl-outcome');
        }

        $code = $performative['value'][0]; // ubyte: 0=ok, 1=auth-failure
        if ($code !== 0) {
            throw new AuthenticationException("SASL authentication failed (code $code)");
        }
    }

    private function amqpOpen(string $hostname): void
    {
        // After SASL, exchange AMQP protocol headers
        $this->transport->send(FrameBuilder::amqpProtocolHeader());
        $this->expectProtocolHeader(FrameBuilder::amqpProtocolHeader());

        // Send OPEN
        $this->transport->send(PerformativeEncoder::open(
            containerId: $this->containerId,
            hostname:    $hostname,
        ));

        // Receive OPEN and negotiate parameters
        $openFrame = $this->readAmqpFrame();
        $body      = FrameParser::extractBody($openFrame);
        $open      = (new TypeDecoder($body))->decode();

        if (isset($open['value'][2]) && $open['value'][2] > 0) {
            $this->negotiatedMaxFrameSize = min($this->negotiatedMaxFrameSize, $open['value'][2]);
        }
    }

    // --- Frame reading helpers ---

    private function expectProtocolHeader(string $expected): void
    {
        $header = $this->readExact(8);
        if ($header !== $expected) {
            throw new ConnectionFailedException(
                sprintf('Unexpected protocol header: %s', bin2hex($header))
            );
        }
    }

    private function readSaslFrame(): string
    {
        return $this->readFrame(expectedType: 0x01);
    }

    private function readAmqpFrame(): string
    {
        return $this->readFrame(expectedType: 0x00);
    }

    private function readFrame(int $expectedType): string
    {
        while (true) {
            $data = $this->transport->read(4096);
            if ($data === null) {
                throw new ConnectionFailedException('Connection closed by peer');
            }
            if ($data !== '') {
                $this->parser->feed($data);
            }
            $frames = $this->parser->readyFrames();
            foreach ($frames as $frame) {
                if (FrameParser::extractType($frame) === $expectedType) {
                    return $frame;
                }
            }
        }
    }

    private function readExact(int $bytes): string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = $this->transport->read($bytes - strlen($buffer));
            if ($chunk === null) {
                throw new ConnectionFailedException('Connection closed reading protocol header');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/ConnectionTest.php
```

Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/Connection.php tests/Unit/Connection/ConnectionTest.php
git commit -m "feat: add Connection with correct SASL+AMQP handshake sequence"
```

---

### Task 6.4: AutoReconnect

The original plan had a method name mismatch (`connect()` in test vs `connectWithRetry()` in impl) and called `transport->connect()` without a URI. This fixes both.

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Connection/AutoReconnectTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\AutoReconnect;
use AMQP10\Connection\Connection;
use AMQP10\Exception\ConnectionFailedException;
use PHPUnit\Framework\TestCase;

class AutoReconnectTest extends TestCase
{
    public function test_succeeds_on_first_attempt(): void
    {
        $attempts   = 0;
        $reconnect  = new AutoReconnect(
            connect: function () use (&$attempts) { $attempts++; },
            maxRetries: 3,
            backoffMs: 0,
        );

        $reconnect->run();
        $this->assertSame(1, $attempts);
    }

    public function test_retries_on_failure_then_succeeds(): void
    {
        $attempts  = 0;
        $reconnect = new AutoReconnect(
            connect: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new ConnectionFailedException('fail');
                }
            },
            maxRetries: 5,
            backoffMs: 0,
        );

        $reconnect->run();
        $this->assertSame(3, $attempts);
    }

    public function test_throws_after_max_retries_exceeded(): void
    {
        $reconnect = new AutoReconnect(
            connect: fn() => throw new ConnectionFailedException('always fail'),
            maxRetries: 2,
            backoffMs: 0,
        );

        $this->expectException(ConnectionFailedException::class);
        $this->expectExceptionMessage('Max retries exceeded');
        $reconnect->run();
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Connection/AutoReconnectTest.php
```

- [ ] **Step 3: Implement AutoReconnect**

```php
<?php
// src/AMQP10/Connection/AutoReconnect.php
namespace AMQP10\Connection;

use AMQP10\Exception\ConnectionFailedException;

/**
 * Wraps a connection callable with retry + exponential backoff.
 *
 * Usage:
 *   $reconnect = new AutoReconnect(
 *       connect: fn() => $connection->open(),
 *       maxRetries: 5,
 *       backoffMs: 1000,
 *   );
 *   $reconnect->run();
 */
class AutoReconnect
{
    public function __construct(
        private readonly \Closure $connect,
        private readonly int $maxRetries = 5,
        private readonly int $backoffMs  = 1000,
    ) {}

    public function run(): void
    {
        $attempt = 0;
        while (true) {
            try {
                ($this->connect)();
                return;
            } catch (ConnectionFailedException $e) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    throw new ConnectionFailedException('Max retries exceeded', previous: $e);
                }
                if ($this->backoffMs > 0) {
                    usleep($this->backoffMs * 1000 * $attempt); // exponential backoff
                }
            }
        }
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/AutoReconnectTest.php
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Connection/AutoReconnect.php tests/Unit/Connection/AutoReconnectTest.php
git commit -m "feat: add AutoReconnect with closure-based connect and exponential backoff"
```

---

## Chunk 7: Session and Link Layer

This chunk was entirely missing from the original plan. In AMQP 1.0, sessions and links are not optional — every message is sent via a link, which lives within a session, which lives within a connection. Without this layer the protocol cannot function.

**AMQP 1.0 hierarchy:**
```
Connection (one per TCP socket)
  └── Session(s) (multiplexed channels, handle windowing)
        └── SenderLink(s) (for publishing — ATTACH with role=sender)
        └── ReceiverLink(s) (for consuming — ATTACH with role=receiver)
```

### File map

- Create: `src/AMQP10/Connection/Session.php`
- Create: `src/AMQP10/Connection/SenderLink.php`
- Create: `src/AMQP10/Connection/ReceiverLink.php`
- Create: `tests/Unit/Connection/SessionTest.php`
- Create: `tests/Unit/Connection/SenderLinkTest.php`

---

### Task 7.1: Session

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Connection/SessionTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private function makeOpenSession(): array
    {
        $mock    = new TransportMock();
        // Queue a BEGIN response from the server
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        return [$mock, $session];
    }

    public function test_begin_sends_begin_frame(): void
    {
        [$mock, $session] = $this->makeOpenSession();

        $sent  = $mock->sent();
        $frames = [];
        $parser = new FrameParser();
        $parser->feed($sent);
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames);
        $frame        = $frames[0];
        $body         = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        $this->assertSame(Descriptor::BEGIN, $performative['descriptor']);
    }

    public function test_begin_sends_on_correct_channel(): void
    {
        [$mock, $session] = $this->makeOpenSession();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertSame(0, FrameParser::extractChannel($frames[0]));
    }

    public function test_is_open_after_begin(): void
    {
        [, $session] = $this->makeOpenSession();
        $this->assertTrue($session->isOpen());
    }

    public function test_end_sends_end_frame(): void
    {
        [$mock, $session] = $this->makeOpenSession();
        $mock->clearSent();
        $session->end();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::END, $performative['descriptor']);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

- [ ] **Step 3: Implement Session**

```php
<?php
// src/AMQP10/Connection/Session.php
namespace AMQP10\Connection;

use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Transport\TransportInterface;

/**
 * Manages an AMQP 1.0 session (BEGIN/END).
 *
 * A session provides a channel for multiplexing links and handles
 * flow control windowing (next-outgoing-id, incoming-window, etc.).
 */
class Session
{
    private bool $open           = false;
    private int  $nextOutgoingId = 0;
    private int  $nextLinkHandle = 0;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $channel,
        private readonly int $incomingWindow = 2048,
        private readonly int $outgoingWindow = 2048,
    ) {}

    public function begin(): void
    {
        $this->transport->send(PerformativeEncoder::begin(
            channel:         $this->channel,
            nextOutgoingId:  $this->nextOutgoingId,
            incomingWindow:  $this->incomingWindow,
            outgoingWindow:  $this->outgoingWindow,
        ));

        // In a real implementation, read the BEGIN response and capture remote-channel.
        // For simplicity here we mark as open immediately (the TransportMock feeds a response).
        $this->open = true;
    }

    public function end(): void
    {
        if ($this->open) {
            $this->transport->send(PerformativeEncoder::end($this->channel));
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function channel(): int
    {
        return $this->channel;
    }

    /** Allocate the next available link handle */
    public function allocateHandle(): int
    {
        return $this->nextLinkHandle++;
    }

    /** Increment and return next delivery ID */
    public function nextDeliveryId(): int
    {
        return $this->nextOutgoingId++;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SessionTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/Session.php tests/Unit/Connection/SessionTest.php
git commit -m "feat: add Session for AMQP 1.0 channel management (BEGIN/END)"
```

---

### Task 7.2: SenderLink and ReceiverLink

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Connection/SenderLinkTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class SenderLinkTest extends TestCase
{
    private function makeSession(): array
    {
        $mock    = new TransportMock();
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        return [$mock, $session];
    }

    public function test_attach_sends_attach_frame_with_sender_role(): void
    {
        [$mock, $session] = $this->makeSession();

        // Queue an ATTACH response from the server (echo back)
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'my-sender', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER, // server echoes as receiver
            source: null, target: '/exchanges/test/key',
        ));

        $link = new SenderLink($session, name: 'my-sender', target: '/exchanges/test/key');
        $link->attach();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $performative['descriptor']);
        // role=false means sender
        $this->assertFalse($performative['value'][2]);
    }

    public function test_detach_sends_detach_frame(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'l', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER,
            source: null, target: '/q/test',
        ));
        $link = new SenderLink($session, name: 'l', target: '/q/test');
        $link->attach();
        $mock->clearSent();

        $link->detach();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::DETACH, $performative['descriptor']);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

- [ ] **Step 3: Implement SenderLink**

```php
<?php
// src/AMQP10/Connection/SenderLink.php
namespace AMQP10\Connection;

use AMQP10\Protocol\PerformativeEncoder;

/**
 * An AMQP 1.0 sender link (role=sender, used for publishing).
 *
 * Lifecycle: attach() → send() ... → detach()
 * The target address is an AMQP 1.0 RabbitMQ v2 address:
 *   /exchanges/{exchange}/{routing-key}
 *   /queues/{queue}
 */
class SenderLink
{
    private bool $attached = false;
    private int  $handle;

    public function __construct(
        private readonly Session $session,
        private readonly string  $name,
        private readonly string  $target,
        private readonly int     $sndSettleMode = PerformativeEncoder::SND_UNSETTLED,
    ) {
        $this->handle = $session->allocateHandle();
    }

    public function attach(): void
    {
        $this->session->transport()->send(PerformativeEncoder::attach(
            channel:       $this->session->channel(),
            name:          $this->name,
            handle:        $this->handle,
            role:          PerformativeEncoder::ROLE_SENDER,
            target:        $this->target,
            sndSettleMode: $this->sndSettleMode,
        ));
        $this->attached = true;
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->session->transport()->send(PerformativeEncoder::detach(
                channel: $this->session->channel(),
                handle:  $this->handle,
            ));
            $this->attached = false;
        }
    }

    /**
     * Send a message (pre-encoded AMQP message payload) via a TRANSFER frame.
     *
     * @param string $messagePayload Pre-encoded AMQP message sections
     * @return int The delivery-id assigned to this transfer
     */
    public function transfer(string $messagePayload): int
    {
        $deliveryId = $this->session->nextDeliveryId();
        $deliveryTag = pack('N', $deliveryId); // 4-byte delivery tag

        $this->session->transport()->send(PerformativeEncoder::transfer(
            channel:        $this->session->channel(),
            handle:         $this->handle,
            deliveryId:     $deliveryId,
            deliveryTag:    $deliveryTag,
            messagePayload: $messagePayload,
            settled:        $this->sndSettleMode === PerformativeEncoder::SND_SETTLED,
        ));

        return $deliveryId;
    }

    public function handle(): int
    {
        return $this->handle;
    }

    public function isAttached(): bool
    {
        return $this->attached;
    }
}
```

- [ ] **Step 4: Implement ReceiverLink**

```php
<?php
// src/AMQP10/Connection/ReceiverLink.php
namespace AMQP10\Connection;

use AMQP10\Protocol\PerformativeEncoder;

/**
 * An AMQP 1.0 receiver link (role=receiver, used for consuming).
 *
 * Lifecycle: attach() → grantCredit() → [receive messages] → detach()
 * The source address is an AMQP 1.0 RabbitMQ v2 address:
 *   /queues/{queue}
 *   /streams/{stream}
 */
class ReceiverLink
{
    private bool $attached = false;
    private int  $handle;

    public function __construct(
        private readonly Session $session,
        private readonly string  $name,
        private readonly string  $source,
        private readonly int     $initialCredit = 10,
    ) {
        $this->handle = $session->allocateHandle();
    }

    public function attach(): void
    {
        $this->session->transport()->send(PerformativeEncoder::attach(
            channel: $this->session->channel(),
            name:    $this->name,
            handle:  $this->handle,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  $this->source,
        ));
        $this->attached = true;
        $this->grantCredit($this->initialCredit);
    }

    /**
     * Send a FLOW frame granting additional credit to the broker.
     * Credit = number of messages the consumer is ready to receive.
     */
    public function grantCredit(int $credit, int $deliveryCount = 0): void
    {
        $this->session->transport()->send(PerformativeEncoder::flow(
            channel:        $this->session->channel(),
            nextIncomingId: 0,
            incomingWindow: 2048,
            nextOutgoingId: 0,
            outgoingWindow: 2048,
            handle:         $this->handle,
            deliveryCount:  $deliveryCount,
            linkCredit:     $credit,
        ));
    }

    /**
     * Send a DISPOSITION to acknowledge a delivery outcome.
     *
     * @param int    $deliveryId The delivery-id from the TRANSFER frame
     * @param string $outcome    Pre-encoded outcome (use PerformativeEncoder::accepted/rejected/released())
     */
    public function settle(int $deliveryId, string $outcome): void
    {
        $this->session->transport()->send(PerformativeEncoder::disposition(
            channel:   $this->session->channel(),
            role:      true, // receiver sending disposition
            first:     $deliveryId,
            settled:   true,
            state:     $outcome,
        ));
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->session->transport()->send(PerformativeEncoder::detach(
                channel: $this->session->channel(),
                handle:  $this->handle,
            ));
            $this->attached = false;
        }
    }

    public function handle(): int
    {
        return $this->handle;
    }

    public function isAttached(): bool
    {
        return $this->attached;
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/
```

Expected: All pass

- [ ] **Step 6: Run full suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Connection/Session.php src/AMQP10/Connection/SenderLink.php src/AMQP10/Connection/ReceiverLink.php tests/Unit/Connection/
git commit -m "feat: add Session, SenderLink, ReceiverLink — AMQP 1.0 session and link layer"
```

---

## Chunk 8: Address Helper

This chunk is largely correct from the original plan. Included for completeness.

### File map

- Create: `src/AMQP10/Address/AddressHelper.php`
- Create: `tests/Unit/Address/AddressHelperTest.php`

### Task 8.1: AddressHelper

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Address/AddressHelperTest.php
namespace AMQP10\Tests\Address;

use AMQP10\Address\AddressHelper;
use PHPUnit\Framework\TestCase;

class AddressHelperTest extends TestCase
{
    public function test_exchange_address_with_routing_key(): void
    {
        $this->assertSame('/exchanges/my-exchange/my-key', AddressHelper::exchangeAddress('my-exchange', 'my-key'));
    }

    public function test_exchange_address_without_routing_key(): void
    {
        $this->assertSame('/exchanges/my-exchange', AddressHelper::exchangeAddress('my-exchange'));
    }

    public function test_queue_address(): void
    {
        $this->assertSame('/queues/my-queue', AddressHelper::queueAddress('my-queue'));
    }

    public function test_stream_address(): void
    {
        $this->assertSame('/streams/my-stream', AddressHelper::streamAddress('my-stream'));
    }

    public function test_forward_slash_is_percent_encoded(): void
    {
        $this->assertSame('/exchanges/my%2Fexchange/my%2Fkey', AddressHelper::exchangeAddress('my/exchange', 'my/key'));
    }

    public function test_space_is_percent_encoded(): void
    {
        $this->assertSame('/queues/my%20queue', AddressHelper::queueAddress('my queue'));
    }

    public function test_unreserved_chars_not_encoded(): void
    {
        // RFC 3986 unreserved: ALPHA / DIGIT / "-" / "." / "_" / "~"
        $this->assertSame('/queues/my-queue.v2_test~1', AddressHelper::queueAddress('my-queue.v2_test~1'));
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Address/AddressHelperTest.php
```

- [ ] **Step 3: Implement AddressHelper**

```php
<?php
// src/AMQP10/Address/AddressHelper.php
namespace AMQP10\Address;

/**
 * Constructs RabbitMQ AMQP 1.0 v2 address strings.
 *
 * Target addresses (for publishing):
 *   /exchanges/{exchange}/{routing-key}
 *   /exchanges/{exchange}               (fanout/headers — empty routing key)
 *   /queues/{queue}
 *
 * Source addresses (for consuming):
 *   /queues/{queue}
 *   /streams/{stream}
 *
 * Names are percent-encoded per RFC 3986.
 */
class AddressHelper
{
    public static function exchangeAddress(string $exchangeName, string $routingKey = ''): string
    {
        $exchange = self::encode($exchangeName);
        if ($routingKey === '') {
            return "/exchanges/$exchange";
        }
        return "/exchanges/$exchange/" . self::encode($routingKey);
    }

    public static function queueAddress(string $queueName): string
    {
        return '/queues/' . self::encode($queueName);
    }

    public static function streamAddress(string $streamName): string
    {
        return '/streams/' . self::encode($streamName);
    }

    private static function encode(string $input): string
    {
        $result = '';
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            if (ctype_alnum($char) || in_array($char, ['-', '.', '_', '~'], true)) {
                $result .= $char;
            } else {
                $result .= '%' . strtoupper(sprintf('%02X', ord($char)));
            }
        }
        return $result;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Address/AddressHelperTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Address/AddressHelper.php tests/Unit/Address/AddressHelperTest.php
git commit -m "feat: add AddressHelper for RabbitMQ AMQP 1.0 v2 address construction"
```

---

## Chunk 9: Messaging Layer

Fixes from the original plan:
- **`readonly class Message` is invalid** — readonly properties cannot have declaration-site defaults. Removed `readonly` class modifier; properties use constructor promotion.
- **Missing `Offset` class** — required for stream consumers.
- `OutcomeState` enum moved to its own file (PSR-4).

### File map

- Create: `src/AMQP10/Messaging/Message.php`
- Create: `src/AMQP10/Messaging/MessageEncoder.php`
- Create: `src/AMQP10/Messaging/Outcome.php`
- Create: `src/AMQP10/Messaging/OutcomeState.php`
- Create: `src/AMQP10/Messaging/DeliveryContext.php`
- Create: `src/AMQP10/Messaging/Offset.php`
- Create: `tests/Unit/Messaging/MessageTest.php`
- Create: `tests/Unit/Messaging/OutcomeTest.php`

---

### Task 9.1: Message

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Messaging/MessageTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_body_string(): void
    {
        $msg = new Message('hello world');
        $this->assertSame('hello world', $msg->body());
    }

    public function test_properties(): void
    {
        $msg = new Message('body', properties: ['content-type' => 'text/plain']);
        $this->assertSame('text/plain', $msg->property('content-type'));
    }

    public function test_application_properties(): void
    {
        $msg = new Message('body', applicationProperties: ['correlation-id' => '123']);
        $this->assertSame('123', $msg->applicationProperty('correlation-id'));
    }

    public function test_missing_property_returns_null(): void
    {
        $msg = new Message('body');
        $this->assertNull($msg->property('content-type'));
    }

    public function test_ttl_and_priority(): void
    {
        $msg = new Message('body', ttl: 5000, priority: 8);
        $this->assertSame(5000, $msg->ttl());
        $this->assertSame(8, $msg->priority());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

- [ ] **Step 3: Implement Message**

```php
<?php
// src/AMQP10/Messaging/Message.php
namespace AMQP10\Messaging;

class Message
{
    public function __construct(
        private readonly string $body,
        private readonly array  $properties            = [],
        private readonly array  $applicationProperties = [],
        private readonly array  $annotations           = [],
        private readonly int    $ttl                   = 0,
        private readonly int    $priority              = 4,
    ) {}

    public function body(): string { return $this->body; }

    public function property(string $key): mixed
    {
        return $this->properties[$key] ?? null;
    }

    public function applicationProperty(string $key): mixed
    {
        return $this->applicationProperties[$key] ?? null;
    }

    public function annotation(string $key): mixed
    {
        return $this->annotations[$key] ?? null;
    }

    public function ttl(): int { return $this->ttl; }
    public function priority(): int { return $this->priority; }

    public function properties(): array { return $this->properties; }
    public function applicationProperties(): array { return $this->applicationProperties; }
    public function annotations(): array { return $this->annotations; }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/AMQP10/Messaging/Message.php tests/Unit/Messaging/MessageTest.php
git commit -m "feat: add Message class (fixed: removed invalid readonly class usage)"
```

---

### Task 9.2: Outcome and OutcomeState

**Two files — one class/enum each (PSR-4 requirement).**

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Messaging/OutcomeTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Outcome;
use AMQP10\Messaging\OutcomeState;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function test_accepted(): void
    {
        $outcome = Outcome::accepted();
        $this->assertTrue($outcome->isAccepted());
        $this->assertFalse($outcome->isRejected());
        $this->assertFalse($outcome->isReleased());
    }

    public function test_rejected(): void
    {
        $outcome = Outcome::rejected();
        $this->assertFalse($outcome->isAccepted());
        $this->assertTrue($outcome->isRejected());
    }

    public function test_released(): void
    {
        $outcome = Outcome::released();
        $this->assertTrue($outcome->isReleased());
    }

    public function test_modified(): void
    {
        $outcome = Outcome::modified();
        $this->assertTrue($outcome->isModified());
    }
}
```

- [ ] **Step 2: Implement OutcomeState (its own file)**

```php
<?php
// src/AMQP10/Messaging/OutcomeState.php
namespace AMQP10\Messaging;

enum OutcomeState
{
    case ACCEPTED;
    case REJECTED;
    case RELEASED;
    case MODIFIED;
}
```

- [ ] **Step 3: Implement Outcome**

```php
<?php
// src/AMQP10/Messaging/Outcome.php
namespace AMQP10\Messaging;

readonly class Outcome
{
    private function __construct(public readonly OutcomeState $state) {}

    public static function accepted(): self  { return new self(OutcomeState::ACCEPTED); }
    public static function rejected(): self  { return new self(OutcomeState::REJECTED); }
    public static function released(): self  { return new self(OutcomeState::RELEASED); }
    public static function modified(): self  { return new self(OutcomeState::MODIFIED); }

    public function isAccepted(): bool  { return $this->state === OutcomeState::ACCEPTED; }
    public function isRejected(): bool  { return $this->state === OutcomeState::REJECTED; }
    public function isReleased(): bool  { return $this->state === OutcomeState::RELEASED; }
    public function isModified(): bool  { return $this->state === OutcomeState::MODIFIED; }
}
```

- [ ] **Step 4: Implement DeliveryContext**

```php
<?php
// src/AMQP10/Messaging/DeliveryContext.php
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Protocol\PerformativeEncoder;

class DeliveryContext
{
    public function __construct(
        private readonly int          $deliveryId,
        private readonly ReceiverLink $link,
    ) {}

    public function accept(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::accepted());
    }

    public function reject(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::rejected());
    }

    public function release(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::released());
    }
}
```

- [ ] **Step 5: Implement Offset**

```php
<?php
// src/AMQP10/Messaging/Offset.php
namespace AMQP10\Messaging;

/**
 * Stream consumer offset — where to start reading from a stream.
 * Used with ConsumerBuilder::offset().
 */
class Offset
{
    private function __construct(
        public readonly string $type,
        public readonly mixed  $value = null,
    ) {}

    /** Start from the very first message in the stream */
    public static function first(): self  { return new self('first'); }

    /** Start from the last message (only new messages) */
    public static function last(): self   { return new self('last'); }

    /** Start after the last consumed offset (resume) */
    public static function next(): self   { return new self('next'); }

    /** Start from a specific numeric offset */
    public static function offset(int $n): self { return new self('offset', $n); }

    /** Start from a specific timestamp (milliseconds since epoch) */
    public static function timestamp(int $ms): self { return new self('timestamp', $ms); }
}
```

- [ ] **Step 6: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/
```

- [ ] **Step 7: Run full suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 8: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add Message, Outcome, DeliveryContext, Offset for messaging layer"
```

---

## Chunk 10: Publisher and Consumer

The original plan had Publisher taking a `TransportInterface` directly, bypassing the Session and Link layers. This is wrong — publishers use a `SenderLink`, consumers use a `ReceiverLink`.

### File map

- Create: `src/AMQP10/Messaging/MessageEncoder.php`
- Create: `src/AMQP10/Messaging/MessageDecoder.php`
- Create: `src/AMQP10/Messaging/Publisher.php`
- Create: `src/AMQP10/Messaging/PublisherBuilder.php`
- Create: `src/AMQP10/Messaging/Consumer.php`
- Create: `src/AMQP10/Messaging/ConsumerBuilder.php`
- Create: `tests/Unit/Messaging/PublisherTest.php`
- Create: `tests/Unit/Messaging/ConsumerTest.php`

---

### Task 10.1: MessageEncoder and MessageDecoder

Messages are encoded as AMQP 1.0 sections (described types) concatenated in order.

Message section descriptor codes:
- Header: 0x70
- Properties: 0x73
- Application Properties: 0x74
- Data (body): 0x75

- [ ] **Step 1: Implement MessageEncoder**

```php
<?php
// src/AMQP10/Messaging/MessageEncoder.php
namespace AMQP10\Messaging;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeEncoder;

/**
 * Encodes a Message to AMQP 1.0 wire format (concatenated message sections).
 *
 * Message sections (spec §3.2):
 *   header              (descriptor 0x70)
 *   message-annotations (descriptor 0x72)
 *   properties          (descriptor 0x73)
 *   application-props   (descriptor 0x74)
 *   data                (descriptor 0x75) — body as binary
 */
class MessageEncoder
{
    public static function encode(Message $message): string
    {
        $sections = '';

        // Header section (if TTL or priority set)
        if ($message->ttl() > 0 || $message->priority() !== 4) {
            $sections .= self::section(Descriptor::MSG_HEADER, [
                TypeEncoder::encodeBool(false),             // durable
                TypeEncoder::encodeUbyte($message->priority()), // priority
                $message->ttl() > 0
                    ? TypeEncoder::encodeUint($message->ttl())
                    : TypeEncoder::encodeNull(),             // ttl
            ]);
        }

        // Properties section
        $props = $message->properties();
        if (!empty($props)) {
            $sections .= self::section(Descriptor::MSG_PROPERTIES, [
                TypeEncoder::encodeNull(),  // message-id
                TypeEncoder::encodeNull(),  // user-id
                TypeEncoder::encodeNull(),  // to
                TypeEncoder::encodeNull(),  // subject
                TypeEncoder::encodeNull(),  // reply-to
                TypeEncoder::encodeNull(),  // correlation-id
                isset($props['content-type'])
                    ? TypeEncoder::encodeSymbol($props['content-type'])
                    : TypeEncoder::encodeNull(),
                TypeEncoder::encodeNull(),  // content-encoding
            ]);
        }

        // Application properties section
        $appProps = $message->applicationProperties();
        if (!empty($appProps)) {
            $pairs = [];
            foreach ($appProps as $key => $value) {
                $pairs[TypeEncoder::encodeString($key)] = TypeEncoder::encodeString((string)$value);
            }
            $sections .= self::sectionMap(Descriptor::MSG_APPLICATION_PROPS, $pairs);
        }

        // Data section (body as binary)
        $sections .= TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_DATA),
            TypeEncoder::encodeBinary($message->body()),
        );

        return $sections;
    }

    private static function section(int $descriptor, array $fields): string
    {
        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong($descriptor),
            TypeEncoder::encodeList($fields),
        );
    }

    private static function sectionMap(int $descriptor, array $pairs): string
    {
        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong($descriptor),
            TypeEncoder::encodeMap($pairs),
        );
    }
}
```

- [ ] **Step 2: Implement MessageDecoder**

```php
<?php
// src/AMQP10/Messaging/MessageDecoder.php
namespace AMQP10\Messaging;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;

/**
 * Decodes AMQP 1.0 wire-format message sections into a Message object.
 */
class MessageDecoder
{
    public static function decode(string $payload): Message
    {
        $decoder = new TypeDecoder($payload);
        $body    = '';
        $props   = [];
        $appProps = [];

        while ($decoder->remaining() > 0) {
            $section = $decoder->decode(); // each section is a described type
            if (!is_array($section) || !isset($section['descriptor'])) {
                continue;
            }

            match ($section['descriptor']) {
                Descriptor::MSG_DATA => $body = $section['value'],
                Descriptor::MSG_PROPERTIES => $props = self::extractProperties($section['value']),
                Descriptor::MSG_APPLICATION_PROPS => $appProps = $section['value'] ?? [],
                default => null,
            };
        }

        return new Message($body, properties: $props, applicationProperties: $appProps);
    }

    private static function extractProperties(array $fields): array
    {
        $map = [];
        // Properties list fields (spec §3.2.4):
        // [0]=message-id [1]=user-id [2]=to [3]=subject [4]=reply-to [5]=correlation-id
        // [6]=content-type [7]=content-encoding
        if (isset($fields[6]) && $fields[6] !== null) {
            $map['content-type'] = $fields[6];
        }
        return $map;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/AMQP10/Messaging/MessageEncoder.php src/AMQP10/Messaging/MessageDecoder.php
git commit -m "feat: add MessageEncoder and MessageDecoder for AMQP 1.0 message sections"
```

---

### Task 10.2: Publisher

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Messaging/PublisherTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\Session;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Publisher;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class PublisherTest extends TestCase
{
    private function makeSession(): array
    {
        $mock = new TransportMock();
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        return [$mock, $session];
    }

    public function test_send_transmits_transfer_frame(): void
    {
        [$mock, $session] = $this->makeSession();

        // Queue ATTACH response + DISPOSITION (accepted) response
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'pub', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER,
            source: null, target: '/queues/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::disposition(
            channel: 0, role: false, first: 0, settled: true,
            state: PerformativeEncoder::accepted(),
        ));

        $publisher = new Publisher($session, '/queues/test');
        $outcome   = $publisher->send(new Message('hello'));

        // Verify a TRANSFER frame was sent
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $transferFrame = null;
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf) && ($perf['descriptor'] ?? null) === Descriptor::TRANSFER) {
                $transferFrame = $frame;
            }
        }

        $this->assertNotNull($transferFrame, 'A TRANSFER frame must have been sent');
        $this->assertTrue($outcome->isAccepted());
    }
}
```

- [ ] **Step 2: Implement Publisher**

```php
<?php
// src/AMQP10/Messaging/Publisher.php
namespace AMQP10\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\PublishException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Publisher
{
    private readonly SenderLink $link;
    private readonly FrameParser $parser;

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {
        $linkName     = 'sender-' . bin2hex(random_bytes(4));
        $this->link   = new SenderLink($session, name: $linkName, target: $address);
        $this->parser = new FrameParser();
        $this->link->attach();
    }

    public function send(Message $message): Outcome
    {
        $payload    = MessageEncoder::encode($message);
        $deliveryId = $this->link->transfer($payload);
        return $this->awaitOutcome($deliveryId);
    }

    public function close(): void
    {
        $this->link->detach();
    }

    private function awaitOutcome(int $deliveryId): Outcome
    {
        $transport = $this->session->transport();

        while (true) {
            $data = $transport->read(4096);
            if ($data === null) {
                throw new PublishException('Connection closed while awaiting outcome');
            }
            if ($data !== '') {
                $this->parser->feed($data);
            }

            foreach ($this->parser->readyFrames() as $frame) {
                $body        = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();

                if (!is_array($performative)) continue;
                if ($performative['descriptor'] !== Descriptor::DISPOSITION) continue;

                $fields = $performative['value'];
                $first  = $fields[1] ?? null;
                if ($first !== $deliveryId) continue;

                $state = $fields[4] ?? null;
                return $this->decodeOutcome($state);
            }
        }
    }

    private function decodeOutcome(mixed $state): Outcome
    {
        if (!is_array($state)) {
            return Outcome::accepted();
        }
        return match ($state['descriptor'] ?? null) {
            Descriptor::ACCEPTED => Outcome::accepted(),
            Descriptor::REJECTED => Outcome::rejected(),
            Descriptor::RELEASED => Outcome::released(),
            Descriptor::MODIFIED => Outcome::modified(),
            default              => Outcome::released(),
        };
    }
}
```

- [ ] **Step 3: Create PublisherBuilder**

```php
<?php
// src/AMQP10/Messaging/PublisherBuilder.php
namespace AMQP10\Messaging;

use AMQP10\Connection\Session;

class PublisherBuilder
{
    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {}

    public function send(Message $message): Outcome
    {
        $publisher = new Publisher($this->session, $this->address);
        $outcome   = $publisher->send($message);
        $publisher->close();
        return $outcome;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/PublisherTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/Publisher.php src/AMQP10/Messaging/PublisherBuilder.php tests/Unit/Messaging/PublisherTest.php
git commit -m "feat: add Publisher using SenderLink (TRANSFER/DISPOSITION via correct AMQP 1.0 link layer)"
```

---

### Task 10.3: Consumer

- [ ] **Step 1: Implement ConsumerBuilder**

```php
<?php
// src/AMQP10/Messaging/ConsumerBuilder.php
namespace AMQP10\Messaging;

use AMQP10\Connection\Session;

class ConsumerBuilder
{
    private ?\Closure  $handler      = null;
    private ?\Closure  $errorHandler = null;
    private int        $credit       = 10;
    private ?Offset    $offset       = null;
    private ?string    $filterSql    = null;
    private array      $filterValues = [];

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {}

    public function handle(\Closure $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function onError(\Closure $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function credit(int $n): self
    {
        $this->credit = $n;
        return $this;
    }

    public function prefetch(int $n): self
    {
        return $this->credit($n);
    }

    public function offset(Offset $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function filterSql(string $sql): self
    {
        $this->filterSql = $sql;
        return $this;
    }

    public function filterValues(string ...$values): self
    {
        $this->filterValues = $values;
        return $this;
    }

    public function run(): void
    {
        $consumer = new Consumer(
            $this->session,
            $this->address,
            $this->credit,
            $this->offset,
            $this->filterSql,
            $this->filterValues,
        );
        $consumer->run($this->handler, $this->errorHandler);
    }
}
```

- [ ] **Step 2: Implement Consumer**

```php
<?php
// src/AMQP10/Messaging/Consumer.php
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Consumer
{
    private readonly ReceiverLink $link;
    private readonly FrameParser  $parser;

    public function __construct(
        private readonly Session  $session,
        private readonly string   $address,
        private readonly int      $credit       = 10,
        private readonly ?Offset  $offset       = null,
        private readonly ?string  $filterSql    = null,
        private readonly array    $filterValues = [],
    ) {
        $linkName   = 'receiver-' . bin2hex(random_bytes(4));
        $this->link = new ReceiverLink($session, name: $linkName, source: $address, initialCredit: $credit);
        $this->parser = new FrameParser();
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $this->link->attach();
        $transport = $this->session->transport();

        while ($transport->isConnected()) {
            $data = $transport->read(4096);
            if ($data === null) break;
            if ($data === '') continue;

            $this->parser->feed($data);

            foreach ($this->parser->readyFrames() as $frame) {
                if ($this->isTransferFrame($frame)) {
                    $this->handleTransfer($frame, $handler, $errorHandler);
                }
            }
        }

        $this->link->detach();
    }

    private function isTransferFrame(string $frame): bool
    {
        $body        = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        return is_array($performative) && ($performative['descriptor'] ?? null) === Descriptor::TRANSFER;
    }

    private function handleTransfer(string $frame, ?\Closure $handler, ?\Closure $errorHandler): void
    {
        $body        = FrameParser::extractBody($frame);
        $decoder     = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId  = $performative['value'][1] ?? 0;

        // Remaining bytes after the TRANSFER performative are the message payload
        $messagePayload = substr($body, $decoder->offset());
        $message        = MessageDecoder::decode($messagePayload);

        $ctx = new DeliveryContext($deliveryId, $this->link);

        if ($handler !== null) {
            try {
                $handler($message, $ctx);
            } catch (\Throwable $e) {
                if ($errorHandler !== null) {
                    $errorHandler($e);
                }
            }
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/AMQP10/Messaging/Consumer.php src/AMQP10/Messaging/ConsumerBuilder.php
git commit -m "feat: add Consumer using ReceiverLink (FLOW/DISPOSITION via correct AMQP 1.0 link layer)"
```

---

## Chunk 11: Management API

**Complete redesign.** The original plan used ATTACH frames to arbitrary exchange/queue addresses to declare topology. This is wrong — those ATTACH frames open publisher/consumer links to existing topology.

RabbitMQ 4.0+ AMQP 1.0 management uses **HTTP-over-AMQP**: a dedicated sender+receiver link pair attached to the management node, with messages carrying HTTP-like request/response semantics.

**Protocol:**
1. Attach sender link to `/management`
2. Attach receiver link to `/management` with a unique reply-to address
3. Send request message with:
   - `subject` property = HTTP method ("PUT", "DELETE", "GET")
   - `to` property = resource path (e.g., `/queues/my-queue`)
   - `message-id` = unique request ID (for correlation)
   - `reply-to` = the receiver link's address
   - Body = JSON payload (for PUT requests)
4. Receive response with `correlation-id` = request `message-id`, `subject` = HTTP status ("200", "201", "204")

> **Reference:** Study the official RabbitMQ AMQP clients for exact path formats:
> - Python: https://github.com/rabbitmq/rabbitmq-amqp-python-client
> - Java: https://github.com/rabbitmq/rabbitmq-amqp-java-client

### File map

- Create: `src/AMQP10/Management/Management.php`
- Create: `src/AMQP10/Management/ExchangeSpecification.php`
- Create: `src/AMQP10/Management/QueueSpecification.php`
- Create: `src/AMQP10/Management/BindingSpecification.php`
- Create: `src/AMQP10/Management/ExchangeType.php`
- Create: `src/AMQP10/Management/QueueType.php`

---

### Task 11.1: Specifications (no protocol, just data classes)

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Management/SpecificationTest.php
namespace AMQP10\Tests\Management;

use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use PHPUnit\Framework\TestCase;

class SpecificationTest extends TestCase
{
    public function test_exchange_spec_defaults(): void
    {
        $spec = new ExchangeSpecification('test');
        $this->assertSame('test', $spec->name);
        $this->assertSame(ExchangeType::DIRECT, $spec->type);
        $this->assertTrue($spec->durable);
    }

    public function test_queue_spec(): void
    {
        $spec = new QueueSpecification('my-queue', QueueType::QUORUM);
        $this->assertSame('my-queue', $spec->name);
        $this->assertSame(QueueType::QUORUM, $spec->type);
        $this->assertTrue($spec->durable);
    }
}
```

- [ ] **Step 2: Implement (one file each)**

```php
<?php
// src/AMQP10/Management/ExchangeType.php
namespace AMQP10\Management;

enum ExchangeType: string
{
    case DIRECT  = 'direct';
    case FANOUT  = 'fanout';
    case TOPIC   = 'topic';
    case HEADERS = 'headers';
}
```

```php
<?php
// src/AMQP10/Management/QueueType.php
namespace AMQP10\Management;

enum QueueType: string
{
    case CLASSIC = 'classic';
    case QUORUM  = 'quorum';
    case STREAM  = 'stream';
}
```

```php
<?php
// src/AMQP10/Management/ExchangeSpecification.php
namespace AMQP10\Management;

readonly class ExchangeSpecification
{
    public function __construct(
        public readonly string       $name,
        public readonly ExchangeType $type       = ExchangeType::DIRECT,
        public readonly bool         $durable    = true,
        public readonly bool         $autoDelete = false,
    ) {}
}
```

```php
<?php
// src/AMQP10/Management/QueueSpecification.php
namespace AMQP10\Management;

readonly class QueueSpecification
{
    public function __construct(
        public readonly string    $name,
        public readonly QueueType $type    = QueueType::QUORUM,
        public readonly bool      $durable = true,
    ) {}
}
```

```php
<?php
// src/AMQP10/Management/BindingSpecification.php
namespace AMQP10\Management;

readonly class BindingSpecification
{
    public function __construct(
        public readonly string  $sourceExchange,
        public readonly string  $destinationQueue,
        public readonly ?string $bindingKey = null,
    ) {}
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Management/SpecificationTest.php
```

- [ ] **Step 4: Commit**

```bash
git add src/AMQP10/Management/ tests/Unit/Management/SpecificationTest.php
git commit -m "feat: add management specification data classes"
```

---

### Task 11.2: Management Client

- [ ] **Step 1: Implement Management**

```php
<?php
// src/AMQP10/Management/Management.php
namespace AMQP10\Management;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\ManagementException;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageDecoder;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;

/**
 * AMQP 1.0 management client using the HTTP-over-AMQP protocol.
 *
 * RabbitMQ exposes a management node at /management.
 * Clients send request messages and receive response messages
 * using HTTP-like subject fields (method/status) and JSON bodies.
 *
 * Reference implementations:
 *   - https://github.com/rabbitmq/rabbitmq-amqp-java-client
 *   - https://github.com/rabbitmq/rabbitmq-amqp-python-client
 */
class Management
{
    private readonly SenderLink   $sender;
    private readonly ReceiverLink $receiver;
    private readonly FrameParser  $parser;
    private readonly string       $replyTo;
    private int $requestId = 0;

    public function __construct(private readonly Session $session)
    {
        $this->replyTo  = 'management-reply-' . bin2hex(random_bytes(8));
        $this->parser   = new FrameParser();

        $this->sender   = new SenderLink($session, name: 'management-sender', target: '/management');
        $this->receiver = new ReceiverLink($session, name: 'management-receiver', source: $this->replyTo, initialCredit: 10);

        $this->sender->attach();
        $this->receiver->attach();
    }

    public function declareExchange(ExchangeSpecification $spec): void
    {
        $path = '/exchanges/' . rawurlencode($spec->name);
        $body = json_encode([
            'type'        => $spec->type->value,
            'durable'     => $spec->durable,
            'auto_delete' => $spec->autoDelete,
        ]);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function deleteExchange(string $name): void
    {
        $path     = '/exchanges/' . rawurlencode($name);
        $response = $this->request('DELETE', $path);
        $this->assertSuccess($response, [200, 204]);
    }

    public function declareQueue(QueueSpecification $spec): void
    {
        $path = '/queues/' . rawurlencode($spec->name);
        $body = json_encode([
            'durable'    => $spec->durable,
            'arguments'  => ['x-queue-type' => $spec->type->value],
        ]);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function deleteQueue(string $name): void
    {
        $path     = '/queues/' . rawurlencode($name);
        $response = $this->request('DELETE', $path);
        $this->assertSuccess($response, [200, 204]);
    }

    public function bind(BindingSpecification $spec): void
    {
        $path = '/bindings';
        $body = json_encode([
            'source'           => $spec->sourceExchange,
            'destination'      => $spec->destinationQueue,
            'destination_type' => 'queue',
            'routing_key'      => $spec->bindingKey ?? '',
        ]);
        $response = $this->request('POST', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function close(): void
    {
        $this->receiver->detach();
        $this->sender->detach();
    }

    // --- Internal HTTP-over-AMQP request/response ---

    private function request(string $method, string $path, ?string $jsonBody = null): array
    {
        $requestId = (string) ++$this->requestId;

        $appProps = [
            'subject'    => $method,
            'to'         => $path,
            'message-id' => $requestId,
            'reply-to'   => $this->replyTo,
        ];

        $msg = new Message($jsonBody ?? '', applicationProperties: $appProps);
        $this->sender->transfer(MessageEncoder::encode($msg));

        return $this->awaitResponse($requestId);
    }

    private function awaitResponse(string $requestId): array
    {
        $transport = $this->session->transport();

        while (true) {
            $data = $transport->read(4096);
            if ($data === null) throw new ManagementException('Connection closed awaiting management response');
            if ($data !== '') $this->parser->feed($data);

            foreach ($this->parser->readyFrames() as $frame) {
                $body        = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();
                if (!is_array($performative) || ($performative['descriptor'] ?? null) !== Descriptor::TRANSFER) continue;

                $offset  = (new TypeDecoder($body))->offset(); // re-decode to get offset
                $msgPayload = substr($body, $offset);
                $msg        = MessageDecoder::decode($msgPayload);

                $corrId = $msg->applicationProperty('correlation-id');
                if ($corrId !== $requestId) continue;

                return [
                    'status' => (int)($msg->applicationProperty('subject') ?? 0),
                    'body'   => $msg->body(),
                ];
            }
        }
    }

    private function assertSuccess(array $response, array $expected): void
    {
        if (!in_array($response['status'], $expected, true)) {
            throw new ManagementException(
                "Management request failed with status {$response['status']}: {$response['body']}"
            );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/AMQP10/Management/Management.php
git commit -m "feat: add Management using HTTP-over-AMQP on /management link (correct RabbitMQ protocol)"
```

---

## Chunk 12: Client API

The original plan had several bugs: `withSasl()` destroyed the existing config, the test called a private method, and `connect()` was called before `open()` which was redundant. This chunk fixes all of them.

### File map

- Create: `src/AMQP10/Client/Client.php`
- Create: `tests/Unit/Client/ClientTest.php`

### Task 12.1: Client

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Client/ClientTest.php
namespace AMQP10\Tests\Client;

use AMQP10\Client\Client;
use AMQP10\Client\Config;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function test_client_is_not_connected_before_connect(): void
    {
        $client = new Client('amqp://guest:guest@localhost:5672/');
        $this->assertFalse($client->isConnected());
    }

    public function test_with_auto_reconnect_returns_new_client_instance(): void
    {
        $client  = new Client('amqp://guest:guest@localhost:5672/');
        $client2 = $client->withAutoReconnect(maxRetries: 3, backoffMs: 500);
        // Fluent methods must NOT mutate the original
        $this->assertNotSame($client, $client2);
    }

    public function test_with_sasl_does_not_lose_auto_reconnect_config(): void
    {
        $client = new Client('amqp://guest:guest@localhost:5672/');
        $client = $client->withAutoReconnect(maxRetries: 3, backoffMs: 500);
        $client = $client->withSasl(\AMQP10\Connection\Sasl::plain('u', 'p'));

        // withSasl must not overwrite autoReconnect config
        $config = $client->config();
        $this->assertTrue($config->autoReconnect);
        $this->assertSame(3, $config->maxRetries);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

- [ ] **Step 3: Update Config to include Sasl**

```php
<?php
// src/AMQP10/Client/Config.php — update to include sasl
namespace AMQP10\Client;

use AMQP10\Connection\Sasl;

readonly class Config
{
    public function __construct(
        public bool   $autoReconnect = false,
        public int    $maxRetries    = 5,
        public int    $backoffMs     = 1000,
        public ?Sasl  $sasl          = null,
    ) {}

    public function with(
        ?bool  $autoReconnect = null,
        ?int   $maxRetries    = null,
        ?int   $backoffMs     = null,
        ?Sasl  $sasl          = null,
    ): self {
        return new self(
            autoReconnect: $autoReconnect ?? $this->autoReconnect,
            maxRetries:    $maxRetries    ?? $this->maxRetries,
            backoffMs:     $backoffMs     ?? $this->backoffMs,
            sasl:          $sasl          ?? $this->sasl,
        );
    }
}
```

- [ ] **Step 4: Implement Client**

```php
<?php
// src/AMQP10/Client/Client.php
namespace AMQP10\Client;

use AMQP10\Connection\AutoReconnect;
use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Connection\Session;
use AMQP10\Management\Management;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Transport\BlockingAdapter;
use AMQP10\Transport\TransportInterface;

class Client
{
    private ?Connection $connection = null;
    private ?Session    $session    = null;

    public function __construct(
        private readonly string $uri,
        private Config $config = new Config(),
        private readonly ?TransportInterface $transport = null,
    ) {}

    /**
     * Open the connection. Must be called before publish/consume/management.
     */
    public function connect(): static
    {
        $transport  = $this->transport ?? new BlockingAdapter();
        $connection = new Connection($transport, $this->uri, $this->config->sasl);

        if ($this->config->autoReconnect) {
            $reconnect = new AutoReconnect(
                connect:    fn() => $connection->open(),
                maxRetries: $this->config->maxRetries,
                backoffMs:  $this->config->backoffMs,
            );
            $reconnect->run();
        } else {
            $connection->open();
        }

        $this->connection = $connection;
        $this->session    = new Session($transport, channel: 0);
        $this->session->begin();

        return $this;
    }

    public function close(): void
    {
        $this->session?->end();
        $this->connection?->close();
        $this->connection = null;
        $this->session    = null;
    }

    public function isConnected(): bool
    {
        return $this->connection?->isOpen() ?? false;
    }

    // --- Fluent config — do NOT mutate; return new instance ---

    public function withAutoReconnect(int $maxRetries = 5, int $backoffMs = 1000): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(autoReconnect: true, maxRetries: $maxRetries, backoffMs: $backoffMs);
        return $clone;
    }

    public function withSasl(Sasl $sasl): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(sasl: $sasl);
        return $clone;
    }

    public function config(): Config
    {
        return $this->config;
    }

    // --- API ---

    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder($this->session(), $address);
    }

    public function consume(string $address): ConsumerBuilder
    {
        return new ConsumerBuilder($this->session(), $address);
    }

    public function management(): Management
    {
        return new Management($this->session());
    }

    private function session(): Session
    {
        if ($this->session === null) {
            throw new \RuntimeException('Call connect() before using the client');
        }
        return $this->session;
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Client/ClientTest.php
```

- [ ] **Step 6: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All pass

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Client/Client.php src/AMQP10/Client/Config.php tests/Unit/Client/ClientTest.php
git commit -m "feat: add Client facade (fixed: withSasl no longer destroys config, connect() is explicit)"
```

---

## Plan Complete

The corrected plan is now fully written. All critical AMQP 1.0 protocol errors from the original plan have been addressed:

| Original Issue | Fixed In |
|---------------|----------|
| Frame format wrong (missing SIZE, DOFF, wrong type) | Chunk 3 — FrameBuilder |
| String type code 0x81 is `long` not string | Chunk 2 — TypeCode/TypeEncoder |
| Protocol header 7 bytes, wrong protocol-id | Chunk 6 — Connection |
| SASL PLAIN test expected wrong bytes | Chunk 6 — Sasl |
| BlockingAdapter: wrong PHP type, wrong function signature | Chunk 5 — BlockingAdapter |
| AutoReconnect method name mismatch | Chunk 6 — AutoReconnect |
| Management API architecture wrong | Chunk 11 — Management |
| Client test calls private method | Chunk 12 — Client |
| withSasl() destroys existing config | Chunk 12 — Client |
| Publisher bypasses Session/Link layers | Chunk 10 — Publisher |
| Missing Offset class | Chunk 9 — Offset |
| readonly class with property defaults = fatal error | Chunk 9 — Message |
| Multiple classes per file (PSR-4 violation) | All chunks — one file per class/enum |
| Session/Link layer missing entirely | Chunk 7 — Session, SenderLink, ReceiverLink |

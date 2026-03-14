<?php

declare(strict_types=1);

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
    public const BOOL_TRUE = 0x41; // true, no payload

    public const BOOL_FALSE = 0x42; // false, no payload

    public const BOOL = 0x56; // 1-byte payload: 0x00=false, 0x01=true

    // Unsigned integer short-forms (§1.6.3–1.6.6)
    public const UINT_ZERO = 0x43; // zero, no payload

    public const UINT_SMALL = 0x52; // 1-byte payload (0–255)

    public const UINT = 0x70; // 4-byte payload

    public const ULONG_ZERO = 0x44; // zero, no payload

    public const ULONG_SMALL = 0x53; // 1-byte payload (0–255)

    public const ULONG = 0x80; // 8-byte payload

    // Other unsigned
    public const UBYTE = 0x50; // 1-byte payload

    public const USHORT = 0x60; // 2-byte payload

    // Signed integers (§1.6.7–1.6.12)
    public const BYTE = 0x51; // 1-byte signed

    public const SHORT = 0x61; // 2-byte signed

    public const INT_SMALL = 0x54; // 1-byte signed payload

    public const INT = 0x71; // 4-byte signed payload

    public const LONG_SMALL = 0x55; // 1-byte signed payload

    public const LONG = 0x81; // 8-byte signed payload

    // Floating point (§1.6.13–1.6.14)
    public const FLOAT = 0x72; // 4-byte IEEE 754

    public const DOUBLE = 0x82; // 8-byte IEEE 754

    // Other primitives
    public const CHAR = 0x73; // 4-byte UTF-32 code point

    public const TIMESTAMP = 0x83; // 8-byte ms since Unix epoch (signed long)

    public const UUID = 0x98; // 16 bytes

    // Variable-width (§1.6.18–1.6.21)
    public const VBIN8 = 0xA0; // binary: 1-byte length prefix

    public const VBIN32 = 0xB0; // binary: 4-byte length prefix

    public const STR8 = 0xA1; // UTF-8 string: 1-byte length prefix (max 255 bytes)

    public const STR32 = 0xB1; // UTF-8 string: 4-byte length prefix

    public const SYM8 = 0xA3; // ASCII symbol: 1-byte length prefix (max 255 bytes)

    public const SYM32 = 0xB3; // ASCII symbol: 4-byte length prefix

    // Compound types (§1.6.22–1.6.25)
    public const LIST0 = 0x45; // empty list, no payload

    public const LIST8 = 0xC0; // 1-byte size + 1-byte count + items

    public const LIST32 = 0xD0; // 4-byte size + 4-byte count + items

    public const MAP8 = 0xC1; // 1-byte size + 1-byte count + alternating key/value

    public const MAP32 = 0xD1; // 4-byte size + 4-byte count

    // Array (§1.6.26–1.6.27) — all elements share one constructor
    public const ARRAY8 = 0xE0; // 1-byte size + 1-byte count + constructor + payloads

    public const ARRAY32 = 0xF0; // 4-byte size + 4-byte count + constructor + payloads

    // Described type marker (§1.6.28)
    // Format: 0x00 + descriptor (any AMQP value) + value (any AMQP value)
    public const DESCRIBED = 0x00;
}

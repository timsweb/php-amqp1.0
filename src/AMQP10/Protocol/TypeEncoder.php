<?php
declare(strict_types=1);
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
     * list8:  constructor(1) + size(1) + count(1) + items
     *   size = count-byte(1) + len(items)
     * @param string[] $items Pre-encoded AMQP values
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
     * @param array<string, string> $pairs Pre-encoded key => pre-encoded value
     */
    public static function encodeMap(array $pairs): string
    {
        if (empty($pairs)) {
            return pack('CCC', TypeCode::MAP8, 1, 0);
        }

        $body  = '';
        $count = 0;
        foreach ($pairs as $key => $value) {
            $body  .= $key . $value;
            $count += 2;
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
     * array8: constructor(1) + size(1) + count(1) + element-constructor(1) + payloads
     *   size = count(1) + element-constructor(1) + len(all-payloads)
     * @param string[] $symbols
     */
    public static function encodeSymbolArray(array $symbols): string
    {
        foreach ($symbols as $sym) {
            if (strlen($sym) > 0xFF) {
                throw new \InvalidArgumentException('Symbol exceeds 255 bytes; use encodeSymbol separately for large symbols');
            }
        }

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
     */
    public static function encodeDescribed(string $descriptor, string $value): string
    {
        return "\x00" . $descriptor . $value;
    }
}

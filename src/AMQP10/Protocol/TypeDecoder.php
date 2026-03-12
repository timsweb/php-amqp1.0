<?php
declare(strict_types=1);
namespace AMQP10\Protocol;

use AMQP10\Exception\FrameException;

class TypeDecoder
{
    private int $offset = 0;

    public function __construct(private readonly string $buffer) {}

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

    private function decodeList8(): array
    {
        $size  = $this->readByte();
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

    private function decodeMap8(): array
    {
        $size  = $this->readByte();
        $count = $this->readByte();
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

    private function decodeArray8(): array
    {
        $size        = $this->readByte();
        $count       = $this->readByte();
        $constructor = $this->readByte();
        return $this->decodeArrayElements($count, $constructor);
    }

    private function decodeArray32(): array
    {
        $size        = $this->readUint32();
        $count       = $this->readUint32();
        $constructor = $this->readByte();
        return $this->decodeArrayElements($count, $constructor);
    }

    private function decodeArrayElements(int $count, int $constructor): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $elementDecoder = new self(pack('C', $constructor) . substr($this->buffer, $this->offset));
            $item           = $elementDecoder->decode();
            $this->offset  += $elementDecoder->offset() - 1; // -1: sub-decoder buffer starts with prepended constructor byte
            $items[]        = $item;
        }
        return $items;
    }

    private function decodeDescribed(): array
    {
        $descriptor = $this->decode();
        $value      = $this->decode();
        return ['descriptor' => $descriptor, 'value' => $value];
    }

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
        return $this->readUint64();
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
        $len = $this->readByte();
        if ($len > $this->remaining()) {
            throw new FrameException("Buffer too short: need {$len} bytes, have {$this->remaining()}");
        }
        $value = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $value;
    }

    private function readVar32(): string
    {
        $len = $this->readUint32();
        if ($len > $this->remaining()) {
            throw new FrameException("Buffer too short: need {$len} bytes, have {$this->remaining()}");
        }
        $value = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $value;
    }
}

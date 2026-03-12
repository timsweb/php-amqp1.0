<?php
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

        // Message annotations section
        $annotations = $message->annotations();
        if (!empty($annotations)) {
            $pairs = [];
            foreach ($annotations as $key => $value) {
                $encodedKey   = TypeEncoder::encodeSymbol((string)$key);
                $encodedValue = match (true) {
                    is_null($value)  => TypeEncoder::encodeNull(),
                    is_bool($value)  => TypeEncoder::encodeBool($value),
                    is_int($value)   => TypeEncoder::encodeUlong($value),
                    default          => TypeEncoder::encodeString((string)$value),
                };
                $pairs[$encodedKey] = $encodedValue;
            }
            $sections .= self::sectionMap(Descriptor::MSG_ANNOTATIONS, $pairs);
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

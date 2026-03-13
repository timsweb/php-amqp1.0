<?php
declare(strict_types=1);
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
        $annotations = [];

        while ($decoder->remaining() > 0) {
            $section = $decoder->decode(); // each section is a described type
            if (!is_array($section) || !isset($section['descriptor'])) {
                continue;
            }

            match ($section['descriptor']) {
                Descriptor::MSG_DATA => $body = $section['value'],
                Descriptor::MSG_ANNOTATIONS => $annotations = $section['value'] ?? [],
                Descriptor::MSG_PROPERTIES => $props = self::extractProperties($section['value']),
                Descriptor::MSG_APPLICATION_PROPS => $appProps = $section['value'] ?? [],
                default => null,
            };
        }

        return new Message($body, properties: $props, applicationProperties: $appProps, annotations: $annotations);
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<string, mixed>
     */
    private static function extractProperties(array $fields): array
    {
        $map = [];
        // Properties list fields (spec §3.2.4):
        $names = [
            0 => 'message-id',
            1 => 'user-id',
            2 => 'to',
            3 => 'subject',
            4 => 'reply-to',
            5 => 'correlation-id',
            6 => 'content-type',
            7 => 'content-encoding',
        ];
        foreach ($names as $index => $name) {
            if (isset($fields[$index]) && $fields[$index] !== null) {
                $map[$name] = $fields[$index];
            }
        }
        return $map;
    }
}

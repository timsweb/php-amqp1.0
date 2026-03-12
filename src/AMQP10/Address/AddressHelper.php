<?php
declare(strict_types=1);

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

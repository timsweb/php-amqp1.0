<?php

declare(strict_types=1);

namespace AMQP10\Protocol;

use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;

/**
 * Encodes AMQP 1.0 performatives as complete frames.
 * Each performative is a described type: \x00 + ulong(descriptor) + list(fields)
 * Spec: Transport §2.7, Security §5.3
 */
class PerformativeEncoder
{
    public const ROLE_SENDER = false;

    public const ROLE_RECEIVER = true;

    public const SND_UNSETTLED = 0;

    public const SND_SETTLED = 1;

    public const SND_MIXED = 2;

    public const RCV_FIRST = 0;

    public const RCV_SECOND = 1;

    public static function open(
        string $containerId,
        ?string $hostname = null,
        int $maxFrameSize = 65536,
        int $channelMax = 65535,
        int $idleTimeOut = 60000,
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

    public static function close(int $channel, ?string $errorDescription = null): string
    {
        $fields = $errorDescription !== null ? [TypeEncoder::encodeString($errorDescription)] : [];

        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::CLOSE, $fields));
    }

    public static function begin(
        int $channel,
        int $nextOutgoingId = 0,
        int $incomingWindow = 2048,
        int $outgoingWindow = 2048,
        int $handleMax = 255,
        ?int $remoteChannel = null,
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

    public static function end(int $channel): string
    {
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::END, []));
    }

    /**
     * @param  array<mixed, mixed>|null  $properties
     */
    public static function attach(
        int $channel,
        string $name,
        int $handle,
        bool $role,
        ?string $source = null,
        ?string $target = null,
        int $sndSettleMode = self::SND_UNSETTLED,
        int $rcvSettleMode = self::RCV_FIRST,
        ?array $properties = null,
        ?int $initialDeliveryCount = null,
        ?string $filterMap = null,
        ?TerminusDurability $durable = null,
        ?ExpiryPolicy $expiryPolicy = null,
    ): string {
        $fields = [
            TypeEncoder::encodeString($name),
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeBool($role),
            TypeEncoder::encodeUbyte($sndSettleMode),
            TypeEncoder::encodeUbyte($rcvSettleMode),
            self::encodeSource($source, $filterMap, $durable, $expiryPolicy),
            self::encodeTarget($target),
            TypeEncoder::encodeNull(), // unsettled
            TypeEncoder::encodeNull(), // incomplete-unsettled
            $initialDeliveryCount !== null
                ? TypeEncoder::encodeUint($initialDeliveryCount)
                : TypeEncoder::encodeNull(),
            TypeEncoder::encodeNull(), // max-message-size
            TypeEncoder::encodeNull(), // offered-capabilities
            TypeEncoder::encodeNull(), // desired-capabilities
            $properties !== null
                ? TypeEncoder::encodeMap($properties)
                : TypeEncoder::encodeNull(),
        ];

        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::ATTACH, $fields));
    }

    public static function detach(int $channel, int $handle, bool $closed = true): string
    {
        $fields = [
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeBool($closed),
        ];

        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::DETACH, $fields));
    }

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

    public static function transfer(
        int $channel,
        int $handle,
        ?int $deliveryId,
        ?string $deliveryTag,
        string $messagePayload,
        bool $settled = false,
        int $messageFormat = 0,
        bool $more = false,
    ): string {
        $fields = [
            TypeEncoder::encodeUint($handle),
            $deliveryId !== null ? TypeEncoder::encodeUint($deliveryId) : TypeEncoder::encodeNull(),
            $deliveryTag !== null ? TypeEncoder::encodeBinary($deliveryTag) : TypeEncoder::encodeNull(),
            TypeEncoder::encodeUint($messageFormat),
            TypeEncoder::encodeBool($settled),
            TypeEncoder::encodeBool($more),
        ];
        $body = self::described(Descriptor::TRANSFER, $fields) . $messagePayload;

        return FrameBuilder::amqp(channel: $channel, body: $body);
    }

    public static function disposition(
        int $channel,
        bool $role,
        int $first,
        ?int $last = null,
        bool $settled = true,
        string $state = '',
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

    public static function modified(bool $deliveryFailed = true, bool $undeliverableHere = true): string
    {
        $fields = [
            TypeEncoder::encodeBool($deliveryFailed),
            TypeEncoder::encodeBool($undeliverableHere),
        ];

        return self::described(Descriptor::MODIFIED, $fields);
    }

    /**
     * @param  array<int, string>  $mechanisms
     */
    public static function saslMechanisms(array $mechanisms): string
    {
        $fields = [TypeEncoder::encodeSymbolArray($mechanisms)];

        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_MECHANISMS, $fields));
    }

    public static function saslInit(string $mechanism, string $initialResponse, ?string $hostname = null): string
    {
        $fields = [
            TypeEncoder::encodeSymbol($mechanism),
            TypeEncoder::encodeBinary($initialResponse),
            $hostname !== null ? TypeEncoder::encodeString($hostname) : TypeEncoder::encodeNull(),
        ];

        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_INIT, $fields));
    }

    public static function saslOutcome(int $code): string
    {
        $fields = [TypeEncoder::encodeUbyte($code)];

        return FrameBuilder::sasl(body: self::described(Descriptor::SASL_OUTCOME, $fields));
    }

    /**
     * @param  array<int, string>  $fields
     */
    private static function described(int $descriptor, array $fields): string
    {
        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong($descriptor),
            TypeEncoder::encodeList($fields),
        );
    }

    private static function encodeSource(
        ?string $address,
        ?string $filterMap = null,
        ?TerminusDurability $durable = null,
        ?ExpiryPolicy $expiryPolicy = null,
    ): string {
        if ($address === null) {
            return TypeEncoder::encodeNull();
        }
        $hasExtras = $filterMap !== null || $durable !== null || $expiryPolicy !== null;
        if (! $hasExtras) {
            $fields = [TypeEncoder::encodeString($address)];
        } else {
            $fields = [
                TypeEncoder::encodeString($address),
                $durable !== null
                    ? TypeEncoder::encodeUint($durable->value)
                    : TypeEncoder::encodeNull(),
                $expiryPolicy !== null
                    ? TypeEncoder::encodeSymbol($expiryPolicy->value)
                    : TypeEncoder::encodeNull(),
                TypeEncoder::encodeNull(), // timeout
                TypeEncoder::encodeNull(), // dynamic
                TypeEncoder::encodeNull(), // dynamic-node-properties
                TypeEncoder::encodeNull(), // distribution-mode
                $filterMap !== null
                    ? $filterMap
                    : TypeEncoder::encodeNull(),
                TypeEncoder::encodeNull(), // default-outcome
                TypeEncoder::encodeNull(), // outcomes
                $filterMap !== null
                    ? TypeEncoder::encodeSymbolArray(['rabbit:stream'])
                    : TypeEncoder::encodeNull(),
            ];
        }

        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::SOURCE), // source descriptor
            TypeEncoder::encodeList($fields),
        );
    }

    private static function encodeTarget(?string $address): string
    {
        if ($address === null) {
            return TypeEncoder::encodeNull();
        }
        $fields = [TypeEncoder::encodeString($address)];

        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::TARGET), // target descriptor
            TypeEncoder::encodeList($fields),
        );
    }
}

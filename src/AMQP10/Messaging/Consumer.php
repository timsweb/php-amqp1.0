<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Client\Client;
use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Exception\FrameException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;

class Consumer
{
    private ?ReceiverLink $link          = null;
    private bool          $attached      = false;
    private bool          $stopRequested = false;
    private int           $received      = 0;

    /** @var array<int, string> */
    private array $partialDeliveries = [];

    public function __construct(
        private readonly Client              $client,
        private readonly string              $address,
        private readonly int                 $credit             = 10,
        private readonly ?Offset             $offset             = null,
        private readonly ?string             $filterJms          = null,
        private readonly ?string             $filterAmqpSql      = null,
        /** @var ?array<string> */
        private readonly ?array              $filterBloomValues  = null,
        private readonly bool                $matchUnfiltered    = false,
        private readonly float               $idleTimeout        = 30.0,
        private readonly ?string             $linkName           = null,
        private readonly ?TerminusDurability $durable            = null,
        private readonly ?ExpiryPolicy       $expiryPolicy       = null,
        private readonly int                 $reconnectRetries   = 0,
        private readonly int                 $reconnectBackoffMs = 1000,
    ) {}

    private function buildLink(Session $session): ReceiverLink
    {
        $name = $this->linkName ?? ('receiver-' . bin2hex(random_bytes(4)));
        return new ReceiverLink(
            session:       $session,
            name:          $name,
            source:        $this->address,
            initialCredit: $this->credit,
            filterMap:     $this->buildFilterMap(),
            durable:       $this->durable,
            expiryPolicy:  $this->expiryPolicy,
        );
    }

    private function ensureAttached(): void
    {
        if (!$this->attached) {
            $this->link = $this->buildLink($this->client->session());
            $this->link->attach();
            $this->attached = true;
        }
    }

    public function stop(): void
    {
        $this->stopRequested = true;
    }

    public function reattach(Session $session): void
    {
        $this->link          = $this->buildLink($session);
        $this->link->attach();
        $this->attached      = true;
        $this->received      = 0;
        $this->stopRequested = false;
    }

    public function receive(): ?Delivery
    {
        $this->ensureAttached();

        $deadline  = microtime(true) + $this->idleTimeout;
        $replenish = (int) floor($this->credit / 2);

        while (true) {
            if ($this->stopRequested) {
                return null;
            }
            $frame = $this->client->session()->nextFrame();
            if ($frame === null) {
                if (!$this->client->session()->transport()->isConnected()) {
                    return null;
                }
                if (microtime(true) >= $deadline) {
                    return null;
                }
                continue;
            }

            $descriptor = $this->getFrameDescriptor($frame);
            if ($descriptor !== Descriptor::TRANSFER) {
                continue;
            }

            $delivery = $this->handleTransferFrame($frame);
            if ($delivery === null) {
                continue;
            }

            $this->received++;
            if ($replenish > 0 && $this->received % $replenish === 0) {
                $this->link->grantCredit((int) ceil($this->credit / 2), $this->received);
            }

            return $delivery;
        }
    }

    private function handleTransferFrame(string $frame): ?Delivery
    {
        $body         = FrameParser::extractBody($frame);
        $decoder      = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId = $performative['value'][1] ?? 0;
        $more       = $performative['value'][5] ?? false;
        $msgPayload = substr($body, $decoder->offset());

        if ($more) {
            $this->partialDeliveries[$deliveryId] = ($this->partialDeliveries[$deliveryId] ?? '') . $msgPayload;
            return null;
        }

        if (isset($this->partialDeliveries[$deliveryId])) {
            $msgPayload = $this->partialDeliveries[$deliveryId] . $msgPayload;
            unset($this->partialDeliveries[$deliveryId]);
        }

        $message = MessageDecoder::decode($msgPayload);
        $ctx     = new DeliveryContext($deliveryId, $this->link);

        return new Delivery($message, $ctx);
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $attempts = 0;
        while (true) {
            try {
                while ($delivery = $this->receive()) {
                    if ($handler !== null) {
                        try {
                            $handler($delivery->message(), $delivery->context());
                        } catch (\Throwable $e) {
                            if ($errorHandler !== null) {
                                $errorHandler($e);
                            }
                        }
                    }
                }
                break;
            } catch (ConnectionFailedException|\RuntimeException $e) {
                if ($this->reconnectRetries === 0 || $attempts >= $this->reconnectRetries) {
                    throw $e;
                }
                $attempts++;
                $backoff = $this->reconnectBackoffMs * $attempts;
                $suspension = \Revolt\EventLoop::getSuspension();
                \Revolt\EventLoop::delay($backoff / 1000, static function () use ($suspension): void {
                    $suspension->resume();
                });
                $suspension->suspend();
                $this->attached = false;
                $this->client->reconnect();
                $this->reattach($this->client->session());
            }
        }
        $this->close();
    }

    public function close(): void
    {
        try {
            $this->link?->detach();
        } catch (\Throwable) {
        }
        $this->attached = false;
    }

    private function getFrameDescriptor(string $frame): ?int
    {
        if (strlen($frame) < 8) {
            return null;
        }
        try {
            $body         = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();
            return is_array($performative) ? ($performative['descriptor'] ?? null) : null;
        } catch (FrameException) {
            return null;
        }
    }

    private function buildFilterMap(): ?string
    {
        if ($this->offset === null &&
            $this->filterJms === null &&
            $this->filterAmqpSql === null &&
            $this->filterBloomValues === null &&
            !$this->matchUnfiltered) {
            return null;
        }

        $pairs = [];

        if ($this->offset !== null) {
            $offsetValue = match ($this->offset->type) {
                'first', 'last', 'next' => TypeEncoder::encodeSymbol($this->offset->type),
                'offset'                => TypeEncoder::encodeUlong((int) $this->offset->value),
                'timestamp'             => TypeEncoder::encodeTimestamp((int) $this->offset->value),
                default                 => TypeEncoder::encodeSymbol('first'),
            };
            // Filter-set values must be described types per AMQP 1.0 spec §3.5.
            // Descriptor is the same symbol as the map key (rabbitmq:stream-offset-spec).
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec');
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $offsetValue);
        }

        if ($this->filterJms !== null) {
            $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
                TypeEncoder::encodeString($this->filterJms);
        }

        if ($this->filterAmqpSql !== null) {
            $mapKey     = TypeEncoder::encodeSymbol('sql-filter');
            $descriptor = TypeEncoder::encodeSymbol('amqp:sql-filter');
            $sqlString  = TypeEncoder::encodeString($this->filterAmqpSql);
            $pairs[$mapKey] = TypeEncoder::encodeDescribed($descriptor, $sqlString);
        }

        if ($this->filterBloomValues !== null) {
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-filter');
            if (count($this->filterBloomValues) === 1) {
                $inner = TypeEncoder::encodeString($this->filterBloomValues[0]);
            } else {
                $inner = TypeEncoder::encodeList(array_map(
                    fn(string $v) => TypeEncoder::encodeString($v),
                    $this->filterBloomValues
                ));
            }
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $inner);
        }

        if ($this->matchUnfiltered) {
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-match-unfiltered');
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, TypeEncoder::encodeBool(true));
        }

        return TypeEncoder::encodeMap($pairs);
    }
}

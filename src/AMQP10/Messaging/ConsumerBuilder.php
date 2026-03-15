<?php

declare(strict_types=1);

namespace AMQP10\Messaging;

use AMQP10\Client\Client;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use Revolt\EventLoop;
use Closure;
use Fiber;
use Throwable;

class ConsumerBuilder
{
    private ?Closure $handler = null;

    private ?Closure $errorHandler = null;

    private int $credit = 10;

    private ?Offset $offset = null;

    private ?string $filterJms = null;

    private ?string $filterAmqpSql = null;

    /** @var ?array<string> */
    private ?array $filterBloomValues = null;

    private bool $matchUnfiltered = false;

    private ?string $linkName = null;

    private ?TerminusDurability $durable = null;

    private ?ExpiryPolicy $expiryPolicy = null;

    private int $reconnectRetries = 0;

    private int $reconnectBackoffMs = 1000;

    /** @var int[] */
    private array $stopSignals = [];

    private ?Closure $signalHandler = null;

    private ?Consumer $cachedConsumer = null;

    public function __construct(
        private readonly Client $client,
        private readonly string $address,
        private float $idleTimeout = 30.0,
    ) {}

    public function handle(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function onError(Closure $handler): self
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

    public function linkName(string $name): self
    {
        $this->linkName = $name;

        return $this;
    }

    public function durable(TerminusDurability $durability = TerminusDurability::UnsettledState): self
    {
        $this->durable = $durability;

        return $this;
    }

    public function expiryPolicy(ExpiryPolicy $policy = ExpiryPolicy::Never): self
    {
        $this->expiryPolicy = $policy;

        return $this;
    }

    public function withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self
    {
        $this->reconnectRetries = $maxRetries;
        $this->reconnectBackoffMs = $backoffMs;

        return $this;
    }

    public function withIdleTimeout(float $timeout): self
    {
        $this->idleTimeout    = $timeout;
        $this->cachedConsumer = null;

        return $this;
    }

    public function filterSql(string $sql): self
    {
        // Maps to RabbitMQ AMQP SQL for streams (primary use case)
        return $this->filterAmqpSql($sql);
    }

    public function filterJms(string $sql): self
    {
        $this->filterJms = $sql;

        return $this;
    }

    public function filterAmqpSql(string $sql): self
    {
        $this->filterAmqpSql = $sql;

        return $this;
    }

    /** @param string|array<string> $values */
    public function filterBloom(string|array $values, bool $matchUnfiltered = false): self
    {
        $this->filterBloomValues = is_array($values) ? $values : [$values];
        $this->matchUnfiltered = $matchUnfiltered;

        return $this;
    }

    /**
     * Register OS signals that trigger graceful consumer shutdown.
     *
     * @param  int|int[]  $signals  e.g. SIGINT, or [SIGINT, SIGTERM]
     * @param  Closure|null  $handler  Optional callback fired on signal before shutdown.
     *                                  Receives the signal number as its only argument.
     *                                  Useful for logging, console output, cleanup, etc.
     *                                  Requires ext-pcntl.
     */
    public function stopOnSignal(int|array $signals, ?Closure $handler = null): self
    {
        $this->stopSignals = is_array($signals) ? $signals : [$signals];
        $this->signalHandler = $handler;

        return $this;
    }

    public function run(): void
    {
        $consumer = $this->consumer();
        $handler = $this->handler;
        $errorHandler = $this->errorHandler;

        $execute = function () use ($consumer, $handler, $errorHandler): void {
            $watcherIds = [];

            if (! empty($this->stopSignals) && \extension_loaded('pcntl')) {
                foreach ($this->stopSignals as $signal) {
                    $watcherIds[] = EventLoop::onSignal(
                        $signal,
                        function () use ($consumer, $signal): void {
                            if ($this->signalHandler !== null) {
                                ($this->signalHandler)($signal);
                            }
                            $consumer->stop();
                        }
                    );
                }
            }

            try {
                $consumer->run($handler, $errorHandler);
            } finally {
                foreach ($watcherIds as $id) {
                    EventLoop::cancel($id);
                }
            }
        };

        if (Fiber::getCurrent() !== null) {
            $execute();

            return;
        }

        $exception = null;
        EventLoop::queue(static function () use ($execute, &$exception): void {
            try {
                $execute();
            } catch (Throwable $e) {
                $exception = $e;
            }
        });
        EventLoop::run();

        if ($exception !== null) {
            throw $exception;
        }
    }

    public function consumer(): Consumer
    {
        if ($this->cachedConsumer === null) {
            $this->cachedConsumer = new Consumer(
                client:             $this->client,
                address:            $this->address,
                credit:             $this->credit,
                offset:             $this->offset,
                filterJms:          $this->filterJms,
                filterAmqpSql:      $this->filterAmqpSql,
                filterBloomValues:  $this->filterBloomValues,
                matchUnfiltered:    $this->matchUnfiltered,
                idleTimeout:        $this->idleTimeout,
                linkName:           $this->linkName,
                durable:            $this->durable,
                expiryPolicy:       $this->expiryPolicy,
                reconnectRetries:   $this->reconnectRetries,
                reconnectBackoffMs: $this->reconnectBackoffMs,
            );
        }

        return $this->cachedConsumer;
    }
}

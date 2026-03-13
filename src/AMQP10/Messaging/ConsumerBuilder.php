<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\Session;

class ConsumerBuilder
{
    private ?\Closure $handler      = null;
    private ?\Closure $errorHandler = null;
    private int       $credit       = 10;
    private ?Offset   $offset       = null;
    private ?string   $filterJms = null;
    private ?string   $filterAmqpSql = null;
    private ?array $filterBloomValues = null;
    private bool $matchUnfiltered = false;

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
        private readonly float   $idleTimeout = 30.0,
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

    public function filterBloom(string|array $values, bool $matchUnfiltered = false): self
    {
        $this->filterBloomValues = is_array($values) ? $values : [$values];
        $this->matchUnfiltered = $matchUnfiltered;
        return $this;
    }

    public function run(): void
    {
        $consumer = $this->consumer();
        $consumer->run($this->handler, $this->errorHandler);
    }

    public function consumer(): Consumer
    {
        return new Consumer(
            $this->session,
            $this->address,
            $this->credit,
            $this->offset,
            $this->filterJms,
            $this->filterAmqpSql,
            $this->filterBloomValues,
            $this->matchUnfiltered,
            $this->idleTimeout,
        );
    }
}

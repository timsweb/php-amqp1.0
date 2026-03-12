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
    private ?string   $filterSql    = null;

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
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
        $this->filterSql = $sql;
        return $this;
    }

    public function run(): void
    {
        $consumer = new Consumer(
            $this->session,
            $this->address,
            $this->credit,
            $this->offset,
            $this->filterSql,
        );
        $consumer->run($this->handler, $this->errorHandler);
    }
}

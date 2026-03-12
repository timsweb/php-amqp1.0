<?php
declare(strict_types=1);

namespace AMQP10\Client;

use AMQP10\Connection\Sasl;

readonly class Config
{
    public function __construct(
        public bool  $autoReconnect = false,
        public int   $maxRetries    = 5,
        public int   $backoffMs     = 1000,
        public ?Sasl $sasl          = null,
    ) {}

    public function with(
        ?bool $autoReconnect = null,
        ?int  $maxRetries    = null,
        ?int  $backoffMs     = null,
        ?Sasl $sasl          = null,
    ): self {
        return new self(
            autoReconnect: $autoReconnect ?? $this->autoReconnect,
            maxRetries:    $maxRetries    ?? $this->maxRetries,
            backoffMs:     $backoffMs     ?? $this->backoffMs,
            sasl:          $sasl          ?? $this->sasl,
        );
    }
}

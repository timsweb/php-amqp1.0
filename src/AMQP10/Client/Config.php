<?php

declare(strict_types=1);

namespace AMQP10\Client;

use AMQP10\Connection\Sasl;

readonly class Config
{
    public function __construct(
        public ?Sasl $sasl = null,
        public float $timeout = 30.0,
        /** @var array<string, mixed> */
        public array $tlsOptions = [],
    ) {}

    /** @param array<string, mixed>|null $tlsOptions */
    public function with(
        ?Sasl $sasl = null,
        ?float $timeout = null,
        ?array $tlsOptions = null,
    ): self {
        return new self(
            sasl: $sasl ?? $this->sasl,
            timeout: $timeout ?? $this->timeout,
            tlsOptions: $tlsOptions ?? $this->tlsOptions,
        );
    }
}

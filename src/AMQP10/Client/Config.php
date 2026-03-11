<?php

namespace AMQP10\Client;

readonly class Config
{
    public function __construct(
        public bool $autoReconnect = false,
        public int $maxRetries = 5,
        public int $backoffMs = 1000,
    ) {}
}

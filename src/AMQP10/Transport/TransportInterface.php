<?php

declare(strict_types=1);

namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;

interface TransportInterface
{
    /** @throws ConnectionFailedException */
    public function connect(string $uri): void;

    public function disconnect(): void;

    /** @throws ConnectionFailedException */
    public function send(string $bytes): void;

    /**
     * Read up to $length bytes. Returns empty string if no data yet.
     * Returns null if connection closed by peer.
     *
     * @throws ConnectionFailedException
     */
    public function read(int $length = 4096): ?string;

    public function isConnected(): bool;
}

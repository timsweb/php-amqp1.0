<?php
declare(strict_types=1);
namespace AMQP10\Transport;

interface TransportInterface
{
    /** @throws \AMQP10\Exception\ConnectionFailedException */
    public function connect(string $uri): void;

    public function disconnect(): void;

    /** @throws \AMQP10\Exception\ConnectionFailedException */
    public function send(string $bytes): void;

    /**
     * Read up to $length bytes. Returns empty string if no data yet.
     * Returns null if connection closed by peer.
     * @throws \AMQP10\Exception\ConnectionFailedException
     */
    public function read(int $length = 4096): ?string;

    public function isConnected(): bool;
}

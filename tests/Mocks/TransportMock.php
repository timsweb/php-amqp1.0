<?php
namespace AMQP10\Tests\Mocks;

use AMQP10\Transport\TransportInterface;

class TransportMock implements TransportInterface
{
    private bool   $connected = false;
    private string $incoming  = '';
    private string $outgoing  = '';

    public function connect(string $uri): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function send(string $bytes): void
    {
        $this->outgoing .= $bytes;
    }

    public function read(int $length = 4096): ?string
    {
        if (!$this->connected || $this->incoming === '') {
            return '';
        }
        $chunk          = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);
        return $chunk;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function queueIncoming(string $bytes): void
    {
        $this->incoming .= $bytes;
    }

    public function sent(): string
    {
        return $this->outgoing;
    }

    public function clearSent(): void
    {
        $this->outgoing = '';
    }
}

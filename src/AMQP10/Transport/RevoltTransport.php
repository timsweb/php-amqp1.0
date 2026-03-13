<?php
declare(strict_types=1);
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;
use Revolt\EventLoop;

class RevoltTransport implements TransportInterface
{
    /** @var resource|null */
    private mixed $stream = null;

    public function __construct(
        private readonly float $readTimeout = 30.0,
        private readonly array $tlsOptions  = [],
    ) {}

    public function connect(string $uri): void
    {
        $parts   = parse_url($uri);
        $host    = $parts['host'] ?? 'localhost';
        $port    = $parts['port'] ?? 5672;
        $tls     = ($parts['scheme'] ?? 'amqp') === 'amqps';
        $address = ($tls ? 'ssl' : 'tcp') . "://$host:$port";

        $context = ($tls && !empty($this->tlsOptions))
            ? stream_context_create(['ssl' => $this->tlsOptions])
            : stream_context_create();

        $stream = @stream_socket_client($address, $errno, $errstr, timeout: 10, context: $context);

        if ($stream === false) {
            throw new ConnectionFailedException("Cannot connect to $address: $errstr (errno $errno)");
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function send(string $bytes): void
    {
        throw new \LogicException('Not yet implemented');
    }

    public function read(int $length = 4096): ?string
    {
        throw new \LogicException('Not yet implemented');
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }
}

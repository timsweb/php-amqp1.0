<?php
declare(strict_types=1);
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;
use Revolt\EventLoop;

class RevoltTransport implements TransportInterface
{
    /** @var resource|null */
    private mixed $stream = null;

    /**
     * @param array<string, mixed> $tlsOptions
     */
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
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }
        $total  = strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false) {
                throw new ConnectionFailedException('Failed to write to socket');
            }
            if ($written === 0) {
                $suspension = EventLoop::getSuspension();
                $resolved   = false;
                $id = EventLoop::onWritable($this->stream, function () use ($suspension, &$resolved) {
                    if (!$resolved) {
                        $resolved = true;
                        $suspension->resume();
                    }
                });
                $suspension->suspend();
                EventLoop::cancel($id);
            } else {
                $offset += $written;
            }
        }
    }

    public function read(int $length = 4096): ?string
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }

        $data = @fread($this->stream, $length);

        if ($data === false) {
            return null;
        }
        if ($data !== '') {
            return $data;
        }
        if (feof($this->stream)) {
            return null;
        }

        // No data yet — suspend until readable or timeout fires
        $suspension = EventLoop::getSuspension();
        $resolved   = false;

        $readId = EventLoop::onReadable($this->stream, function () use ($suspension, &$resolved) {
            if (!$resolved) {
                $resolved = true;
                $suspension->resume(true);
            }
        });
        $timeoutId = EventLoop::delay($this->readTimeout, function () use ($suspension, &$resolved) {
            if (!$resolved) {
                $resolved = true;
                $suspension->resume(false);
            }
        });

        $hasData = $suspension->suspend();
        EventLoop::cancel($readId);
        EventLoop::cancel($timeoutId);

        if (!$hasData) {
            return '';
        }

        $data = @fread($this->stream, $length);
        if ($data === false || ($data === '' && feof($this->stream))) {
            return null;
        }
        return $data;
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }
}

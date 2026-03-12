<?php
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;

class BlockingAdapter implements TransportInterface
{
    /** @var resource|null */
    private mixed $stream = null;

    public function connect(string $uri): void
    {
        $parts = parse_url($uri);
        $host  = $parts['host'] ?? 'localhost';
        $port  = $parts['port'] ?? 5672;
        $tls   = ($parts['scheme'] ?? 'amqp') === 'amqps';

        $address = ($tls ? 'ssl' : 'tcp') . "://$host:$port";
        $stream  = @stream_socket_client($address, $errno, $errstr, timeout: 10);

        if ($stream === false) {
            throw new ConnectionFailedException("Cannot connect to $address: $errstr (errno $errno)");
        }

        stream_set_blocking($stream, true);
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
            $written = fwrite($this->stream, substr($bytes, $offset));
            if ($written === false) {
                throw new ConnectionFailedException('Failed to write to socket');
            }
            $offset += $written;
        }
    }

    public function read(int $length = 4096): ?string
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }
        $data = fread($this->stream, $length);
        if ($data === false || $data === '') {
            return null; // connection closed
        }
        return $data;
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }
}

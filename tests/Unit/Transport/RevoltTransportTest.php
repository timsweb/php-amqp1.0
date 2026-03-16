<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Transport;

use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Transport\RevoltTransport;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use ReflectionProperty;

class RevoltTransportTest extends TestCase
{
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        return $pair;
    }

    public function test_is_connected_returns_false_before_connect(): void
    {
        $t = new RevoltTransport();
        $this->assertFalse($t->isConnected());
    }

    public function test_connect_fails_on_bad_address(): void
    {
        $this->expectException(ConnectionFailedException::class);
        $t = new RevoltTransport();
        $t->connect('amqp://127.0.0.1:19999'); // nothing listening
    }

    public function test_disconnect_is_safe_when_not_connected(): void
    {
        $t = new RevoltTransport();
        $t->disconnect(); // must not throw
        $this->assertFalse($t->isConnected());
    }

    public function test_send_and_read_over_socket_pair(): void
    {
        [$a, $b] = $this->socketPair();
        stream_set_blocking($b, true);

        $t = new RevoltTransport();
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        EventLoop::run(function () use ($t, $b) {
            $t->send('hello');
            $received = fread($b, 4096);
            $this->assertSame('hello', $received);
            EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_data_when_available(): void
    {
        [$a, $b] = $this->socketPair();
        stream_set_blocking($b, true);

        $t = new RevoltTransport(readTimeout: 1.0);
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        EventLoop::run(function () use ($t, $b) {
            fwrite($b, 'world');
            $data = $t->read(4096);
            $this->assertSame('world', $data);
            EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_empty_string_on_timeout(): void
    {
        [$a, $b] = $this->socketPair();

        $t = new RevoltTransport(readTimeout: 0.05);
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        EventLoop::run(function () use ($t) {
            $data = $t->read(4096);
            $this->assertSame('', $data);
            EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_null_on_closed_connection(): void
    {
        [$a, $b] = $this->socketPair();

        $t = new RevoltTransport(readTimeout: 1.0);
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        EventLoop::run(function () use ($t, $b) {
            fclose($b);
            EventLoop::delay(0.02, function () use ($t) {
                $data = $t->read(4096);
                $this->assertNull($data);
                EventLoop::stop();
            });
        });
    }

    public function test_send_and_read_work_outside_event_loop(): void
    {
        [$a, $b] = $this->socketPair();
        stream_set_blocking($b, true);

        $t = new RevoltTransport();
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        // Called with NO EventLoop::run() wrapper — must work transparently
        $t->send('ping');
        $received = fread($b, 4096);
        $this->assertSame('ping', $received);

        fclose($b);
    }
}

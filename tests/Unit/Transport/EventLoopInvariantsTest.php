<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Transport;

use AMQP10\Transport\RevoltTransport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;

/**
 * Canary tests for the Revolt EventLoop invariants this library relies on.
 *
 * The library's "transparent event loop" pattern — where RevoltTransport::send()
 * and read() work from outside a Fiber by wrapping operations in
 * EventLoop::queue() + EventLoop::run() — only terminates correctly when no
 * referenced persistent watchers are running.
 *
 * Background/periodic watchers (e.g. the heartbeat timer in Client::connect())
 * MUST be unreferenced via EventLoop::unreference(). If they are not, any call
 * to EventLoop::run() will block forever waiting for the next timer tick.
 *
 * If a test in this file hangs, a referenced persistent watcher has been
 * introduced somewhere. Find it and add EventLoop::unreference().
 */
class EventLoopInvariantsTest extends TestCase
{
    /**
     * Core invariant: an unreferenced repeat watcher must not prevent
     * EventLoop::run() from returning once all queued work is done.
     */
    public function test_event_loop_exits_when_only_unreferenced_repeat_watcher_remains(): void
    {
        $watcherId = EventLoop::repeat(60.0, static function (): void {});
        EventLoop::unreference($watcherId);

        $completed = false;
        EventLoop::queue(static function () use (&$completed): void {
            $completed = true;
        });

        EventLoop::run();
        EventLoop::cancel($watcherId);

        $this->assertTrue($completed);
    }

    /**
     * Regression test for the heartbeat timer bug.
     *
     * Client::connect() registers EventLoop::repeat() for the AMQP heartbeat.
     * If that watcher is not unreferenced, RevoltTransport::send() called from
     * outside a Fiber (via EventLoop::queue + run) hangs forever.
     *
     * This test simulates that scenario: a repeat watcher exists (as the
     * heartbeat would), and a send() is called outside the event loop.
     */
    public function test_send_outside_event_loop_completes_with_background_repeat_watcher(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($a, false);
        stream_set_blocking($b, true);

        $watcherId = EventLoop::repeat(60.0, static function (): void {});
        EventLoop::unreference($watcherId); // exactly what Client::connect() does

        $transport = new RevoltTransport();
        $ref = new ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setValue($transport, $a);

        $transport->send('ping'); // must not hang

        EventLoop::cancel($watcherId);

        $received = fread($b, 4096);
        fclose($b);

        $this->assertSame('ping', $received);
    }
}

# Consumer Stop Control — Design Spec

**Date:** 2026-03-15
**Status:** Draft

---

## Problem

`ConsumerBuilder::run()` blocks and gives the caller no handle on the `Consumer` instance it creates internally. The only way to stop a running consumer is via OS signals through `stopOnSignal()`. Common patterns — stop after N messages, stop after a time limit, stop on an external flag (e.g. a Redis key set during deployment) — are all awkward or impossible without dropping down to the lower-level `consumer()->receive()` loop manually.

---

## Goal

Make `Consumer::stop()` accessible to user code without changing the handler signature or adding library-owned "stop after N" features. The library provides the primitive; users compose their own termination logic.

---

## Design

### Core change: cache `Consumer` in `ConsumerBuilder`

`ConsumerBuilder::consumer()` will cache its result, mirroring how `PublisherBuilder::publisher()` already works.

```php
private ?Consumer $cachedConsumer = null;

public function consumer(): Consumer
{
    if ($this->cachedConsumer === null) {
        $this->cachedConsumer = new Consumer(
            client:             $this->client,
            address:            $this->address,
            // ... all existing constructor args
        );
    }
    return $this->cachedConsumer;
}
```

`ConsumerBuilder::run()` already calls `$this->consumer()` internally, so it automatically uses the cached instance — no other changes to `run()` are needed.

### How users get the consumer reference

Call `consumer()` before `run()`. The builder holds all configuration (handler, signals, reconnect settings); the consumer reference is for external stop control only.

```php
$builder = $client->consume('/queues/events')
    ->handle($this->handleMessage(...))
    ->stopOnSignal([SIGINT, SIGTERM]);

$this->consumer = $builder->consumer(); // grab reference
$builder->run();                        // blocks, uses same cached instance
```

---

## Patterns Enabled

### Class-based worker — stop from handler

```php
class PopularityWorker
{
    private Consumer $consumer;
    private int $count = 0;

    public function run(Client $client): void
    {
        $builder = $client->consume('/queues/popularity-updates')
            ->handle($this->handle(...))
            ->stopOnSignal([SIGINT, SIGTERM]);

        $this->consumer = $builder->consumer();
        $builder->run();
    }

    private function handle(Message $msg, DeliveryContext $ctx): void
    {
        $ctx->accept();
        if (++$this->count >= 1000) {
            $this->consumer->stop();
        }
    }
}
```

### Time-based — Revolt delay

Register the timer before `run()`. Revolt picks it up when the event loop starts.

```php
$builder  = $client->consume('/queues/events')->handle($handler);
$consumer = $builder->consumer();

$timerId = EventLoop::delay(3600.0, fn() => $consumer->stop());
$builder->run(); // blocks
EventLoop::cancel($timerId); // cancel if consumer exited before timer fired
```

**Important:** `EventLoop::delay()` is a referenced watcher. If the consumer exits before the timer fires (idle timeout, signal, etc.), the timer would keep the event loop alive for the remaining duration. Cancelling after `run()` returns is required.

### External flag — unreferenced repeat

An unreferenced repeat watcher fires regularly but does not prevent the event loop from exiting once the consumer stops.

```php
$builder  = $client->consume('/queues/events')->handle($handler);
$consumer = $builder->consumer();

$watcherId = EventLoop::repeat(5.0, function () use ($consumer, &$watcherId): void {
    if (shouldRestart()) {
        $consumer->stop();
        EventLoop::cancel($watcherId);
    }
});
EventLoop::unreference($watcherId);
$builder->run();
```

### Count-based — inline closure

```php
$count    = 0;
$builder  = $client->consume('/queues/events');
$consumer = $builder->consumer();

$builder->handle(function (Message $msg, DeliveryContext $ctx) use ($consumer, &$count): void {
    $ctx->accept();
    if (++$count >= 1000) {
        $consumer->stop();
    }
})->run();
```

---

## Contract and Edge Cases

**Configure before materialising.** Builder settings (credit, offset, filters, etc.) must be set before `consumer()` or `run()` is called. Calling a builder setter after `consumer()` has been called has no effect on the cached instance — consistent with `PublisherBuilder`.

**`stop()` is cooperative.** `Consumer::receive()` checks `$stopRequested` at the top of each iteration, after each `transport->read()` call returns. With the default 30-second read timeout, a stop signal may not be observed for up to 30 seconds if the queue is idle. Users with tighter latency requirements should pass a shorter `idleTimeout` to `ConsumerBuilder`'s constructor via `$client->consume($address, idleTimeout: 5.0)`.

**`run()` calls `consumer()` — always.** If the user never calls `consumer()` manually, `run()` creates and caches the instance as before. No behaviour change for existing code.

**Reconnect.** `Consumer::run()` handles reconnect internally via `reattach()` on the same consumer instance. The cached reference remains valid across reconnects.

---

## What Is Not Changing

- No new builder methods (`stopAfter()`, `stopWhen()` etc.) — YAGNI. The primitive is `stop()`; users compose termination logic themselves.
- Handler signature unchanged — `fn(Message $msg, DeliveryContext $ctx)`.
- `Consumer::stop()` behaviour unchanged.
- `stopOnSignal()` unchanged.

---

## Tests

New unit tests in `ConsumerBuilderTest`:

1. `consumer()` returns the same instance on repeated calls.
2. `run()` uses the cached instance from a prior `consumer()` call.
3. Calling a builder setter (e.g. `credit()`) after `consumer()` does not affect the cached instance — "configure before materialise" contract is enforced by the cache, not by throwing.
4. Count-based stop: consumer stops after exactly N messages using a reference obtained via `consumer()`.
5. Time-based stop simulation: `EventLoop::delay()` fires and calls `stop()`; `run()` returns; delayed timer is cancelled cleanly.

---

## Files Changed

| File | Change |
|------|--------|
| `src/AMQP10/Messaging/ConsumerBuilder.php` | Add `$cachedConsumer` property; update `consumer()` to cache |
| `tests/Unit/Messaging/ConsumerBuilderTest.php` | New tests per above |
| `README.md` | Add "Consumer Stop Control" section documenting the patterns |

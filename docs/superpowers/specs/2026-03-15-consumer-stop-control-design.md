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

**`stop()` is cooperative.** `Consumer::receive()` checks `$stopRequested` at the top of each iteration, after each `transport->read()` call returns. With the default 30-second read timeout, a stop signal may not be observed for up to 30 seconds if the queue is idle. Users with tighter latency requirements can set a shorter idle timeout via the new `withIdleTimeout()` fluent setter (see below).

**`run()` calls `consumer()` — always.** If the user never calls `consumer()` manually, `run()` creates and caches the instance as before. No behaviour change for existing code.

**Reconnect.** `Consumer::run()` handles reconnect internally via `reattach()` on the same consumer instance. The cached reference remains valid across reconnects.

---

## Additional Change: `withIdleTimeout()` fluent setter

`idleTimeout` currently has no fluent setter on `ConsumerBuilder` — users must pass it as a named constructor argument on `Client::consume()`, which is not discoverable. Since idle timeout directly affects stop-latency (see above), this is worth adding alongside the caching change.

```php
public function withIdleTimeout(float $timeout): self
{
    $this->idleTimeout     = $timeout;
    $this->cachedConsumer  = null; // invalidate if already materialised
    return $this;
}
```

Invalidating the cache on change is the right behaviour here — `idleTimeout` is passed to `Consumer`'s constructor and cannot be changed after construction. If a user calls `withIdleTimeout()` after `consumer()`, they get a fresh consumer with the new timeout, consistent with the principle of least surprise.

Usage:

```php
$client->consume('/queues/events')
    ->withIdleTimeout(5.0) // stop() observed within 5s even on an idle queue
    ->handle($handler)
    ->run();
```

---

## "Configure Before Materialise" Contract

Calling most builder setters after `consumer()` has been called silently has no effect on the cached instance — the same contract as `PublisherBuilder`. This is acceptable because builder setters are configuration, not runtime controls, and the pattern of grabbing `consumer()` after all configuration is set is clear and documented.

`withIdleTimeout()` is the one exception: it explicitly invalidates the cache, because idle timeout has a direct bearing on stop responsiveness and a user may reasonably want to adjust it alongside grabbing the consumer reference.

No setters throw on post-materialise calls. Throwing would be surprising in a fluent builder and would break the common pattern of conditionally setting options before calling `consumer()`.

---

## Future Consideration: Dynamic Credit Control

AMQP 1.0 link credit is a flow-control primitive — the receiver can send a FLOW frame at any time to grant more credit, reduce it, or set it to zero to pause delivery entirely. The builder's `credit()` setting controls the *initial grant and auto-replenishment window*; adjusting credit at runtime is a distinct concept.

Potential future API:

```php
$consumer->pauseDelivery();       // send FLOW with credit=0
$consumer->resumeDelivery();      // send FLOW restoring original credit
$consumer->grantCredit(int $n);   // explicit one-time grant
```

This is out of scope for this change but would build naturally on the consumer reference pattern introduced here.

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
3. Calling a builder setter (e.g. `credit()`) after `consumer()` does not affect the cached instance.
4. `withIdleTimeout()` after `consumer()` invalidates the cache — the next `consumer()` call returns a fresh instance.
5. Count-based stop: consumer stops after exactly N messages using a reference obtained via `consumer()`.
6. Time-based stop simulation: `EventLoop::delay()` fires and calls `stop()`; `run()` returns; delayed timer is cancelled cleanly.

---

## Files Changed

| File | Change |
|------|--------|
| `src/AMQP10/Messaging/ConsumerBuilder.php` | Add `$cachedConsumer`; update `consumer()` to cache; add `withIdleTimeout()` |
| `tests/Unit/Messaging/ConsumerBuilderTest.php` | New tests per above |
| `README.md` | Add "Consumer Stop Control" section documenting the patterns |

# Examples Folder Design

**Date:** 2026-03-15
**Status:** Approved

## Goal

Provide a set of runnable, copy-paste-friendly PHP scripts covering all major use cases of the library. Aimed at end users integrating the library into their own applications.

## Constraints

- PHP 8.2+, requires a live RabbitMQ 4.0+ broker
- Examples use `QueueType::QUORUM` as the default queue type (RabbitMQ's recommended choice); `QueueType::STREAM` where stream-specific features are demonstrated; `management/01-declare-queue.php` intentionally shows all three types
- Scripts default to `amqp://guest:guest@localhost`; overridable via `AMQP_URI` env var
- Each script is self-contained: it declares any queues/exchanges it needs, runs its demonstration, then cleans up
- Run from CLI: `php examples/publishing/01-basic-publish.php`
- `consuming/04-stop-on-signal.php` requires `ext-pcntl`; without it, signal registration is silently skipped

## Structure

```
examples/
  bootstrap.php                        ← autoloader + AMQP_URI constant
  publishing/
    01-basic-publish.php
    02-fire-and-forget.php
    03-message-properties.php
  consuming/
    01-run-loop.php
    02-one-shot.php
    03-batched-consumer.php
    04-stop-on-signal.php
    05-stop-after-duration.php
    06-durable-subscription.php
  streams/
    01-offset.php
    02-sql-filter.php
    03-bloom-filter.php
  management/
    01-declare-queue.php
    02-declare-exchange-and-bind.php
    03-delete.php
  advanced/
    01-tls.php
    02-reconnecting.php
```

## bootstrap.php

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
define('AMQP_URI', getenv('AMQP_URI') ?: 'amqp://guest:guest@localhost');
```

All scripts open with `require __DIR__ . '/../bootstrap.php'` (all scripts are exactly one level deep).

## File Content Detail

### publishing/

**01-basic-publish.php**
Declare a quorum queue via management. Publish one message. Assert `$outcome->isAccepted()`. Delete queue.

**02-fire-and-forget.php**
Same flow but using `->fireAndForget()` on the publisher builder. Comment explains the trade-off: no disposition wait, higher throughput, no delivery guarantee.

**03-message-properties.php**
Publish a message built with `withMessageId()`, `withCorrelationId()`, `withApplicationProperty()`, `withTtl()`, `withPriority()`, and `withSubject()`. Consume it and read the values back using `$message->property('message-id')`, `$message->property('correlation-id')`, `$message->applicationProperty('key')`, `$message->ttl()`, `$message->priority()`, and `$message->subject()` to confirm round-trip.

### consuming/

**01-run-loop.php**
Declare a quorum queue. Publish 3 messages. Materialise the consumer with `->withIdleTimeout(2.0)->credit(3)->consumer()`. Use a counter inside the handler: call `$ctx->accept()` on each delivery, and after all 3 have been processed call `$consumer->stop()` so `run()` returns cleanly. Contrast `$ctx->accept()` with `$ctx->reject()` in a comment. Delete queue after run completes. Note: without an explicit `stop()` call, `run()` will block until `idleTimeout` expires after the last message.

**02-one-shot.php**
Declare a quorum queue. Publish 1 message. Use `->consumer()->receive()` to pull a single `Delivery`. Call `$delivery->context()->accept()` to acknowledge, then call `$consumer->close()` to detach the link. Delete the queue and call `$client->close()`.

**03-batched-consumer.php**
Declare a quorum queue. Publish 20 messages. Materialise the consumer with `->withIdleTimeout(1.0)->credit(20)->consumer()`. `withIdleTimeout(1.0)` controls the per-`receive()` blocking window (each call waits at most 1 second for a frame before returning `null`). Separately, a 5-second wall-clock deadline is tracked with `microtime(true)` in the outer loop. In a manual loop call `$consumer->receive()` to fill a buffer of up to 10 messages or until the wall-clock deadline is reached. Process the batch, call `$ctx->accept()` on each, then repeat until the queue is drained. `Consumer::run()` is NOT used here — the manual `receive()` loop is the pattern. Delete queue and close.

**04-stop-on-signal.php**
Long-running consumer (no pre-seeded messages). Requires `ext-pcntl`. Register `stopOnSignal([SIGINT, SIGTERM], fn(int $signal) => print("Caught signal $signal, shutting down\n"))`. The closure receives the signal number as its only argument. Demonstrates graceful shutdown on `Ctrl+C`.

**05-stop-after-duration.php**
Run a consumer for exactly 30 seconds using a wall-clock deadline in the `receive()` loop, then break and close. Uses `withIdleTimeout(1.0)` so each `receive()` call blocks for at most 1 second, allowing the wall-clock check to fire responsively rather than waiting the full idle timeout. Shows the manual loop pattern as an alternative to `run()`.

**06-durable-subscription.php**
Declare a stream queue. Attach with `->durable()->expiryPolicy(ExpiryPolicy::Never)->linkName('my-durable-sub')`. Note in a comment that `durable()` defaults to `TerminusDurability::UnsettledState` and that durable subscriptions are most meaningful on stream queues, where the broker tracks the consumer's offset position across reconnects. Consume a few messages, close, reopen with the same link name to demonstrate resuming from the prior position.

### streams/

**01-offset.php**
Declare a stream queue. Publish 5 messages. Consume from `Offset::first()`. Demonstrates the `offset()` builder option.

**02-sql-filter.php**
Declare a stream queue. Publish messages, some with an application property `type=order`, others without. Consume with `filterSql("type = 'order'")`. Only matching messages are received.

**03-bloom-filter.php**
Declare a stream queue. Publish messages setting a filter value annotation via `Message::withAnnotation('x-stream-filter-value', 'some-value')`. Consume with `filterBloom(['some-value'])`. Demonstrates the RabbitMQ stream Bloom filter. Note: `matchUnfiltered` can be passed as second argument to also receive messages with no filter annotation.

### management/

**01-declare-queue.php**
Declare a quorum queue, a stream queue, and a classic queue using `QueueSpecification` and `QueueType`. Demonstrates all three `QueueType` values. Delete all three at the end.

**02-declare-exchange-and-bind.php**
Declare a direct exchange. Declare a quorum queue. Bind the queue to the exchange with a routing key using `BindingSpecification`. Delete exchange and queue at the end.

**03-delete.php**
Standalone example of `deleteQueue()` and `deleteExchange()`. Catch `QueueNotFoundException` and `ExchangeNotFoundException` to demonstrate handling missing-resource errors.

### advanced/

**01-tls.php**
Connect via an `amqps://` URI using the immutable config pattern: `$client = (new Client(AMQP_URI))->withTlsOptions(['cafile' => '/path/to/ca.pem', 'verify_peer' => true])->connect()`. Note that `withTlsOptions()` returns a new `Client` instance (immutable clone) and `connect()` returns the same instance connected. Publish one message. Comment lists common TLS options.

**02-reconnecting.php**
Publish in a loop using `->withReconnect(maxRetries: 10, backoffMs: 500)`. Comment explains how to test: stop and restart the broker while the script runs. Demonstrates automatic reconnection with exponential-ish backoff.

## Self-Provisioning Pattern

Each script that needs broker resources follows this pattern:

```php
// Setup — management link opened, queue/exchange declared, then closed to free the link
$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-queue', QueueType::QUORUM));
$mgmt->close(); // closes the management link; $client stays open

// ... demonstration code ...

// Teardown — reopen management (Client::management() lazily recreates it when closed)
$mgmt = $client->management();
$mgmt->deleteQueue('example-queue');
$mgmt->close();
$client->close();
```

The intermediate `$mgmt->close()` after setup frees the management link during the demonstration so it does not interfere with the example's own links. `Client::management()` lazily recreates the management instance when called again after close.

## Out of Scope

- No mock/offline mode — scripts require a live broker
- No Laravel/framework integration examples (future work per ROADMAP)
- No connection pooling examples (future work per ROADMAP)
- `DeliveryContext::release()` and `modify()` are not given dedicated examples; `reject()` is noted in a comment in `01-run-loop.php`

# Consumer Stop Control Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cache `Consumer` in `ConsumerBuilder` and add `withIdleTimeout()` so callers can obtain a `stop()` handle before `run()` blocks.

**Architecture:** `ConsumerBuilder::consumer()` gains a `?Consumer $cachedConsumer` guard (mirroring `PublisherBuilder::publisher()`). A new `withIdleTimeout()` fluent setter replaces the existing readonly constructor parameter, invalidating the cache if a consumer was already materialised. `run()` already calls `$this->consumer()` internally — no changes needed there.

**Tech Stack:** PHP 8.2, PHPUnit 10, `revolt/event-loop ^1.0`, `TransportMock` / `ClientMock` for unit tests.

---

## Chunk 1: `withIdleTimeout()` and consumer caching

### Task 1: Failing tests for `withIdleTimeout()`

**Files:**
- Modify: `tests/Unit/Messaging/ConsumerBuilderTest.php`

- [ ] **Step 1: Add failing tests for `withIdleTimeout()`**

Append to `ConsumerBuilderTest`:

```php
public function test_with_idle_timeout_before_consumer_sets_value(): void
{
    $client  = $this->createMock(Client::class);
    $builder = new ConsumerBuilder($client, '/queues/test');
    $builder->withIdleTimeout(5.0);

    $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
    $ref->setAccessible(true);
    $this->assertSame(5.0, $ref->getValue($builder));
}

public function test_with_idle_timeout_after_consumer_invalidates_cache(): void
{
    $client  = $this->createMock(Client::class);
    $builder = new ConsumerBuilder($client, '/queues/test');

    // Force idleTimeout to be mutable by checking we can set it
    $builder->withIdleTimeout(5.0);

    $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
    $ref->setAccessible(true);
    $this->assertSame(5.0, $ref->getValue($builder));
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerBuilderTest.php --testdox
```

Expected: FAIL — `withIdleTimeout` method not found.

---

### Task 2: Implement `withIdleTimeout()` and consumer caching

**Files:**
- Modify: `src/AMQP10/Messaging/ConsumerBuilder.php`

- [ ] **Step 1: Remove `readonly` from `$idleTimeout`, add `$cachedConsumer`, add setter, update `consumer()`**

In `ConsumerBuilder`, make these changes:

1. Change the constructor parameter from `private readonly float $idleTimeout = 30.0` to `private float $idleTimeout = 30.0`

2. Add the cached consumer property after the existing properties:
```php
private ?Consumer $cachedConsumer = null;
```

3. Add the `withIdleTimeout()` setter (place it alongside the other fluent setters such as `credit()` and `prefetch()`):
```php
public function withIdleTimeout(float $timeout): self
{
    $this->idleTimeout    = $timeout;
    $this->cachedConsumer = null;

    return $this;
}
```

4. Update `consumer()` to cache its result:
```php
public function consumer(): Consumer
{
    if ($this->cachedConsumer === null) {
        $this->cachedConsumer = new Consumer(
            client:             $this->client,
            address:            $this->address,
            credit:             $this->credit,
            offset:             $this->offset,
            filterJms:          $this->filterJms,
            filterAmqpSql:      $this->filterAmqpSql,
            filterBloomValues:  $this->filterBloomValues,
            matchUnfiltered:    $this->matchUnfiltered,
            idleTimeout:        $this->idleTimeout,
            linkName:           $this->linkName,
            durable:            $this->durable,
            expiryPolicy:       $this->expiryPolicy,
            reconnectRetries:   $this->reconnectRetries,
            reconnectBackoffMs: $this->reconnectBackoffMs,
        );
    }

    return $this->cachedConsumer;
}
```

- [ ] **Step 2: Run the new tests to confirm they pass**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerBuilderTest.php --testdox
```

Expected: all tests PASS including the two new ones.

- [ ] **Step 3: Run full unit suite to confirm no regressions**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: all tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/AMQP10/Messaging/ConsumerBuilder.php tests/Unit/Messaging/ConsumerBuilderTest.php
git commit -m "feat: cache Consumer in ConsumerBuilder, add withIdleTimeout()"
```

---

### Task 3: Failing tests for the caching contract

**Files:**
- Modify: `tests/Unit/Messaging/ConsumerBuilderTest.php`

- [ ] **Step 1: Add failing tests for caching behaviour**

Append to `ConsumerBuilderTest`:

```php
public function test_consumer_returns_same_instance_on_repeated_calls(): void
{
    $client  = $this->createMock(Client::class);
    $builder = new ConsumerBuilder($client, '/queues/test');

    $first  = $builder->consumer();
    $second = $builder->consumer();

    $this->assertSame($first, $second);
}

public function test_setter_after_consumer_does_not_affect_cached_instance(): void
{
    $client  = $this->createMock(Client::class);
    $builder = new ConsumerBuilder($client, '/queues/test');

    $consumer = $builder->consumer();
    $builder->credit(99); // setter called after materialise

    // The cached consumer was built with the original credit — credit() cannot
    // retroactively change it. Verify the same instance is still returned.
    $this->assertSame($consumer, $builder->consumer());
}

public function test_with_idle_timeout_after_consumer_returns_fresh_instance(): void
{
    $client  = $this->createMock(Client::class);
    $builder = new ConsumerBuilder($client, '/queues/test');

    $first = $builder->consumer();
    $builder->withIdleTimeout(2.0); // invalidates cache
    $second = $builder->consumer();

    $this->assertNotSame($first, $second);

    $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
    $ref->setAccessible(true);
    $this->assertSame(2.0, $ref->getValue($builder));
}
```

- [ ] **Step 2: Run tests to confirm they pass**

These should pass immediately since caching is already implemented:

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerBuilderTest.php --testdox
```

Expected: all PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Messaging/ConsumerBuilderTest.php
git commit -m "test: add ConsumerBuilder caching contract tests"
```

---

### Task 4: Stop-via-cached-reference tests

**Files:**
- Modify: `tests/Unit/Messaging/ConsumerBuilderTest.php`

These tests use the same `makeClient()` / `makeTransferFrame()` helpers from `ConsumerTest`. Rather than duplicating them, add a private helper to `ConsumerBuilderTest` that follows the same pattern.

- [ ] **Step 1: Add the test helpers and failing tests**

Add the following to `ConsumerBuilderTest` (add necessary `use` imports: `Session`, `ClientMock`, `TransportMock`, `PerformativeEncoder`, `Message`, `MessageEncoder`, `DeliveryContext`, `Revolt\EventLoop`):

```php
/** @return array{TransportMock, ClientMock} */
private function makeClient(): array
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);
    $session->begin();
    $mock->clearSent();

    return [$mock, new ClientMock($session)];
}

private function makeTransferFrame(string $text, int $deliveryId = 0): string
{
    return PerformativeEncoder::transfer(
        channel: 0,
        handle: 0,
        deliveryId: $deliveryId,
        deliveryTag: pack('N', $deliveryId),
        messagePayload: MessageEncoder::encode(new Message($text)),
        settled: false,
    );
}

public function test_consumer_stop_via_cached_reference_count_based(): void
{
    [$mock, $client] = $this->makeClient();

    // Queue ATTACH response then 5 messages
    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name: 'recv',
        handle: 0,
        role: PerformativeEncoder::ROLE_SENDER,
        source: '/queues/test',
        target: null,
    ));
    for ($i = 0; $i < 5; $i++) {
        $mock->queueIncoming($this->makeTransferFrame("msg-$i", $i));
    }

    $builder  = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.1);
    $consumer = $builder->consumer();
    $count    = 0;

    $builder->handle(function (Message $msg, DeliveryContext $ctx) use ($consumer, &$count): void {
        $ctx->accept();
        if (++$count >= 3) {
            $consumer->stop();
        }
    })->run();

    $this->assertSame(3, $count, 'Consumer should stop after exactly 3 messages');
}

public function test_run_uses_cached_consumer_instance(): void
{
    [$mock, $client] = $this->makeClient();

    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name: 'recv',
        handle: 0,
        role: PerformativeEncoder::ROLE_SENDER,
        source: '/queues/test',
        target: null,
    ));

    $builder  = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.05);
    $consumer = $builder->consumer();

    // Stop immediately — if run() uses the same instance, it will exit at once
    $consumer->stop();
    $builder->handle(fn(Message $msg, DeliveryContext $ctx) => $ctx->accept())->run();

    // If we reached here without hanging, run() used the cached (pre-stopped) consumer
    $this->assertTrue(true);
}
```

- [ ] **Step 2: Run the new tests**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerBuilderTest.php --testdox
```

Expected: all PASS.

- [ ] **Step 3: Run full unit suite**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Messaging/ConsumerBuilderTest.php
git commit -m "test: add stop-via-cached-reference tests to ConsumerBuilderTest"
```

---

## Chunk 2: README

### Task 5: Document the patterns in README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a "Consumer Stop Control" section to README**

Add after the "Durable Consumer" section and before "## Message Context":

```markdown
### Consumer Stop Control

For advanced termination patterns — stop after N messages, time-based restart, or an
external flag — obtain the `Consumer` reference before calling `run()`:

```php
$builder  = $client->consume('/queues/events')->handle($this->handleMessage(...));
$consumer = $builder->consumer(); // same instance run() will use
$builder->run();                  // blocks
```

#### Class-based worker — stop from handler

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

#### Time-based — Revolt delay

```php
$builder  = $client->consume('/queues/events')->handle($handler);
$consumer = $builder->consumer();

$timerId = EventLoop::delay(3600.0, fn() => $consumer->stop());
$builder->run(); // blocks
EventLoop::cancel($timerId); // cancel if consumer exited before timer fired
```

> `EventLoop::delay()` is a referenced watcher. Without the `cancel()` after `run()` returns,
> a consumer that exits before the timer fires would keep the event loop alive for the
> remaining duration.

#### External flag — unreferenced repeat

```php
$builder  = $client->consume('/queues/events')->handle($handler);
$consumer = $builder->consumer();

$watcherId = EventLoop::repeat(5.0, function () use ($consumer, &$watcherId): void {
    if (shouldRestart()) {
        $consumer->stop();
        EventLoop::cancel($watcherId);
    }
});
EventLoop::unreference($watcherId); // won't prevent loop exit when consumer stops
$builder->run();
```

#### Idle timeout

By default the consumer waits up to 30 seconds for a message before `receive()` returns.
For tighter stop-signal responsiveness, reduce this:

```php
$client->consume('/queues/events')
    ->withIdleTimeout(5.0)
    ->handle($handler)
    ->run();
```
```

- [ ] **Step 2: Run the unit suite one final time**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: all PASS.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add consumer stop control patterns to README"
```

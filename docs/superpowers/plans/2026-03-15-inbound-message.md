# InboundMessage Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the split `Delivery`/`DeliveryContext` consumer API with a single `InboundMessage` object that exposes both message data and settlement methods directly.

**Architecture:** Create `InboundMessage` (composes `Message` + `DeliveryContext`), update `Consumer` to return/pass it, delete the now-redundant `Delivery` class, and update all tests and examples.

**Tech Stack:** PHP 8.1+, PHPUnit, no new dependencies

---

## Chunk 1: InboundMessage class, Consumer, and tests

### Task 1: Create InboundMessageTest and InboundMessage

**Files:**
- Create: `tests/Unit/Messaging/InboundMessageTest.php`
- Create: `src/AMQP10/Messaging/InboundMessage.php`
- Delete: `tests/Unit/Messaging/DeliveryContextTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Messaging/InboundMessageTest.php`:

```php
<?php

declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\InboundMessage;
use AMQP10\Messaging\Message;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class InboundMessageTest extends TestCase
{
    private function makeInbound(string $body = 'hello'): InboundMessage
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = new Message(
            body: $body,
            subject: 'test-subject',
            ttl: 5000,
            priority: 3,
            durable: false,
        );

        return new InboundMessage($message, new DeliveryContext(0, $link));
    }

    public function test_body_delegates_to_message(): void
    {
        $this->assertSame('hello world', $this->makeInbound('hello world')->body());
    }

    public function test_subject_delegates_to_message(): void
    {
        $this->assertSame('test-subject', $this->makeInbound()->subject());
    }

    public function test_durable_delegates_to_message(): void
    {
        $this->assertFalse($this->makeInbound()->durable());
    }

    public function test_ttl_delegates_to_message(): void
    {
        $this->assertSame(5000, $this->makeInbound()->ttl());
    }

    public function test_priority_delegates_to_message(): void
    {
        $this->assertSame(3, $this->makeInbound()->priority());
    }

    public function test_property_delegates_to_message(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withCorrelationId('abc-123');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('abc-123', $msg->property('correlation-id'));
    }

    public function test_properties_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withCorrelationId('abc-123');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['correlation-id' => 'abc-123'], $msg->properties());
    }

    public function test_application_property_delegates(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withApplicationProperty('x-type', 'order');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('order', $msg->applicationProperty('x-type'));
    }

    public function test_application_properties_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withApplicationProperty('x-type', 'order');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['x-type' => 'order'], $msg->applicationProperties());
    }

    public function test_annotation_delegates(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withAnnotation('x-stream-filter-value', 'eu');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('eu', $msg->annotation('x-stream-filter-value'));
    }

    public function test_annotations_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withAnnotation('x-stream-filter-value', 'eu');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['x-stream-filter-value' => 'eu'], $msg->annotations());
    }

    public function test_message_escape_hatch_returns_original(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = new Message('body');
        $inbound = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame($message, $inbound->message());
    }

    public function test_accept_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(42, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(42, $link));
        $msg->accept();
    }

    public function test_reject_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(1, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(1, $link));
        $msg->reject();
    }

    public function test_release_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(7, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(7, $link));
        $msg->release();
    }

    public function test_modify_sends_modified_outcome(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(42, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(42, $link));
        $msg->modify(deliveryFailed: true, undeliverableHere: true);

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]);
        $this->assertTrue($decoded['value'][1]);
    }

    public function test_modify_defaults(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(1, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(1, $link));
        $msg->modify();

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]);
        $this->assertTrue($decoded['value'][1]);
    }

    public function test_modify_with_false_flags(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(5, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(5, $link));
        $msg->modify(deliveryFailed: false, undeliverableHere: false);

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertFalse($decoded['value'][0]);
        $this->assertFalse($decoded['value'][1]);
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/InboundMessageTest.php
```

Expected: Error — class `InboundMessage` not found.

- [ ] **Step 3: Create InboundMessage**

Create `src/AMQP10/Messaging/InboundMessage.php`:

```php
<?php

declare(strict_types=1);

namespace AMQP10\Messaging;

readonly class InboundMessage
{
    public function __construct(
        private Message $message,
        private DeliveryContext $context,
    ) {}

    public function body(): string
    {
        return $this->message->body();
    }

    public function subject(): ?string
    {
        return $this->message->subject();
    }

    public function durable(): bool
    {
        return $this->message->durable();
    }

    public function ttl(): int
    {
        return $this->message->ttl();
    }

    public function priority(): int
    {
        return $this->message->priority();
    }

    public function property(string $key): mixed
    {
        return $this->message->property($key);
    }

    public function applicationProperty(string $key): mixed
    {
        return $this->message->applicationProperty($key);
    }

    public function annotation(string $key): mixed
    {
        return $this->message->annotation($key);
    }

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return $this->message->properties();
    }

    /** @return array<string, mixed> */
    public function applicationProperties(): array
    {
        return $this->message->applicationProperties();
    }

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return $this->message->annotations();
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function accept(): void
    {
        $this->context->accept();
    }

    public function reject(): void
    {
        $this->context->reject();
    }

    public function release(): void
    {
        $this->context->release();
    }

    public function modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void
    {
        $this->context->modify($deliveryFailed, $undeliverableHere);
    }
}
```

- [ ] **Step 4: Run test to confirm it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/InboundMessageTest.php
```

Expected: All tests pass.

- [ ] **Step 5: Delete DeliveryContextTest**

```bash
rm tests/Unit/Messaging/DeliveryContextTest.php
```

- [ ] **Step 6: Confirm full suite still passes**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass (DeliveryContextTest removed, replaced by InboundMessageTest).

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Messaging/InboundMessage.php tests/Unit/Messaging/InboundMessageTest.php tests/Unit/Messaging/DeliveryContextTest.php
git commit -m "feat: add InboundMessage unified consumer interface"
```

---

### Task 2: Update Consumer to return InboundMessage

**Files:**
- Modify: `src/AMQP10/Messaging/Consumer.php`
- Modify: `tests/Unit/Messaging/ConsumerTest.php`

- [ ] **Step 1: Update ConsumerTest to expect InboundMessage**

In `tests/Unit/Messaging/ConsumerTest.php`, make these changes:

**Replace imports** — remove `Delivery` and `DeliveryContext`; add `InboundMessage`. **Keep `use AMQP10\Messaging\Message;`** — it is still used in `makeTransferFrame()` and several non-handler tests (`test_get_frame_descriptor_returns_transfer_descriptor`, etc.):

```php
// Remove these lines:
use AMQP10\Messaging\Delivery;
use AMQP10\Messaging\DeliveryContext;

// Add this line:
use AMQP10\Messaging\InboundMessage;

// Keep this line (Message is still needed for construction in helpers):
// use AMQP10\Messaging\Message;
```

**Update `test_consumer_calls_handler_with_message_and_delivery_context`** — rename and change handler/assertions:

```php
public function test_consumer_calls_handler_with_inbound_message(): void
{
    [$mock, $session, $client] = $this->makeClient();

    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name: 'recv',
        handle: 0,
        role: PerformativeEncoder::ROLE_SENDER,
        source: '/queues/test',
        target: null,
    ));
    $mock->queueIncoming($this->makeTransferFrame(0, 'hello', deliveryId: 0));

    $received = [];
    $msgs = [];

    $consumer = new Consumer($client, '/queues/test', credit: 1);
    $consumer->run(function (InboundMessage $msg) use (&$received, &$msgs, $mock) {
        $received[] = $msg->body();
        $msgs[] = $msg;
        $mock->disconnect();
    });

    $this->assertCount(1, $received);
    $this->assertSame('hello', $received[0]);
    $this->assertInstanceOf(InboundMessage::class, $msgs[0]);
}
```

**Update `test_consumer_handles_multiple_messages`** — handler signature:

```php
$consumer->run(function (InboundMessage $msg) use (&$received, &$count, $mock) {
    $received[] = $msg->body();
    $count++;
    if ($count >= 2) {
        $mock->disconnect();
    }
});
```

**Update `test_consumer_error_handler_called_on_exception`** — handler signature:

```php
$consumer->run(
    function (InboundMessage $msg) use ($mock) {
        $mock->disconnect();
        throw new RuntimeException('handler failed');
    },
    function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    }
);
```

**Update `test_consumer_builder_fluent_api`** — handler signature:

```php
$builder
    ->credit(5)
    ->handle(function (InboundMessage $msg) use (&$received, $mock) {
        $received[] = $msg->body();
        $mock->disconnect();
    })
    ->run();
```

**Update `test_receive_returns_delivery`** — rename and change assertions:

```php
public function test_receive_returns_inbound_message(): void
{
    [$mock, $session, $client] = $this->makeClient();

    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name: 'recv',
        handle: 0,
        role: PerformativeEncoder::ROLE_SENDER,
        source: '/queues/test',
        target: null,
    ));
    $mock->queueIncoming($this->makeTransferFrame(0, 'hello', deliveryId: 0));

    $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05);
    $msg = $consumer->receive();

    $this->assertNotNull($msg);
    $this->assertInstanceOf(InboundMessage::class, $msg);
    $this->assertSame('hello', $msg->body());

    $consumer->close();
}
```

**Update `test_receive_multiple`** — use direct accessors:

```php
$this->assertSame('first', $d1->body());
$this->assertSame('second', $d2->body());
```

**Update `test_consumer_builder_consumer_method`** — use direct accessor:

```php
$this->assertNotNull($delivery);
$this->assertSame('via-builder', $delivery->body());
```

**Update `test_consumer_stops_when_transport_disconnects`** and `test_run_returns_on_idle_timeout`** — change handler type hint from `Message $msg` to `InboundMessage $msg`:

```php
$consumer->run(function (InboundMessage $msg) use (&$called) {
    $called = true;
});
```

**Update `test_consumer_credit_replenishment`** — change handler type hint:

```php
$consumer->run(function (InboundMessage $msg) use (&$count, $mock) {
    $count++;
    if ($count >= 5) {
        $mock->disconnect();
    }
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

Expected: Multiple failures — handler signatures and type assertions reference old types.

- [ ] **Step 3: Update Consumer.php**

In `src/AMQP10/Messaging/Consumer.php`:

**No import changes needed** — both `Delivery` and `InboundMessage` are in the same `AMQP10\Messaging` namespace as `Consumer`, so neither requires a `use` statement. Nothing to add or remove.

**Update `handleTransferFrame()` return type and construction:**

```php
private function handleTransferFrame(string $frame): ?InboundMessage
{
    $body = FrameParser::extractBody($frame);
    $decoder = new TypeDecoder($body);
    $performative = $decoder->decode();

    $deliveryId = $performative['value'][1] ?? 0;
    $more = $performative['value'][5] ?? false;
    $msgPayload = substr($body, $decoder->offset());

    if ($more) {
        $this->partialDeliveries[$deliveryId] = ($this->partialDeliveries[$deliveryId] ?? '') . $msgPayload;

        return null;
    }

    if (isset($this->partialDeliveries[$deliveryId])) {
        $msgPayload = $this->partialDeliveries[$deliveryId] . $msgPayload;
        unset($this->partialDeliveries[$deliveryId]);
    }

    $message = MessageDecoder::decode($msgPayload);
    assert($this->link !== null);
    $ctx = new DeliveryContext($deliveryId, $this->link);

    return new InboundMessage($message, $ctx);
}
```

**Update `receive()` return type:**

```php
public function receive(): ?InboundMessage
```

**Update `run()` handler call** — change `$handler($delivery->message(), $delivery->context())` to `$handler($delivery)`:

```php
$handler($delivery);
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/Consumer.php tests/Unit/Messaging/ConsumerTest.php
git commit -m "feat: Consumer::receive() and run() now use InboundMessage"
```

---

### Task 3: Update ConsumerBuilderTest and delete Delivery

**Files:**
- Modify: `tests/Unit/Messaging/ConsumerBuilderTest.php`
- Delete: `src/AMQP10/Messaging/Delivery.php`

- [ ] **Step 1: Update ConsumerBuilderTest**

In `tests/Unit/Messaging/ConsumerBuilderTest.php`:

**Replace imports** — remove `DeliveryContext`; add `InboundMessage`. **Keep `use AMQP10\Messaging\Message;`** — it is still used in `makeTransferFrame()` at line 140:

```php
// Remove:
use AMQP10\Messaging\DeliveryContext;

// Add:
use AMQP10\Messaging\InboundMessage;

// Keep (used in makeTransferFrame and other helpers):
// use AMQP10\Messaging\Message;
```

**Update `test_consumer_stop_via_cached_reference_count_based`** — handler signature:

```php
$builder->handle(function (InboundMessage $msg) use ($consumer, &$count): void {
    $msg->accept();
    if (++$count >= 3) {
        $consumer->stop();
    }
})->run();
```

**Update `test_run_uses_cached_consumer_instance`** — handler signature:

```php
$builder->handle(fn(InboundMessage $msg) => $msg->accept())->run();
```

- [ ] **Step 2: Run tests to confirm they pass**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerBuilderTest.php
```

Expected: All tests pass.

- [ ] **Step 3: Delete Delivery.php**

```bash
rm src/AMQP10/Messaging/Delivery.php
```

- [ ] **Step 4: Run full unit suite to confirm nothing references Delivery**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass. If any test fails with "class Delivery not found", find the reference and update it.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Messaging/ConsumerBuilderTest.php src/AMQP10/Messaging/Delivery.php
git commit -m "refactor: remove Delivery class, update ConsumerBuilderTest"
```

---

## Chunk 2: Examples

### Task 4: Update all consuming examples

**Files:**
- Modify: `examples/consuming/01-run-loop.php`
- Modify: `examples/consuming/02-one-shot.php`
- Modify: `examples/consuming/03-batched-consumer.php`
- Modify: `examples/consuming/04-stop-on-signal.php`
- Modify: `examples/consuming/05-stop-after-duration.php`
- Modify: `examples/consuming/06-durable-subscription.php`
- Modify: `examples/streams/01-offset.php`
- Modify: `examples/streams/02-sql-filter.php`
- Modify: `examples/streams/03-bloom-filter.php`

**Pattern applied across all files using `$delivery->message()->body()` / `$delivery->context()->accept()`:**

```php
// Before:
$delivery->message()->body()   →   $delivery->body()
$delivery->context()->accept() →   $delivery->accept()
$delivery->context()->reject() →   $delivery->reject()
```

**Pattern applied across all files using `run()` handler:**

```php
// Before:
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;
function (Message $message, DeliveryContext $ctx) { ... $message->body() ... $ctx->accept() }

// After:
use AMQP10\Messaging\InboundMessage;
function (InboundMessage $msg) { ... $msg->body() ... $msg->accept() }
```

**Also remove `use AMQP10\Messaging\Delivery;` imports where present.**

- [ ] **Step 1: Update examples/consuming/01-run-loop.php**

Replace imports:
```php
// Remove:
use AMQP10\Messaging\DeliveryContext;
// Add:
use AMQP10\Messaging\InboundMessage;
// Keep (used for Message::create() in the publish loop):
// use AMQP10\Messaging\Message;
```

Also update the inline documentation comments above the handler to reflect the new API:
```php
// The handler receives InboundMessage — message data and settlement in one object.
// Call $msg->accept() to acknowledge the delivery.
// Call $msg->reject() instead to send the message to the dead-letter exchange.
```

Replace handler:
```php
function (Message $message, DeliveryContext $ctx) use (&$count, $total, $consumer): void {
    $count++;
    echo "Received ($count/$total): " . $message->body() . "\n";
    $ctx->accept();
    // $ctx->reject(); // nack — routes to dead-letter exchange

    if ($count >= $total) {
        $consumer->stop();
    }
}
```
With:
```php
function (InboundMessage $msg) use (&$count, $total, $consumer): void {
    $count++;
    echo "Received ($count/$total): " . $msg->body() . "\n";
    $msg->accept();
    // $msg->reject(); // nack — routes to dead-letter exchange

    if ($count >= $total) {
        $consumer->stop();
    }
}
```

- [ ] **Step 2: Update examples/consuming/02-one-shot.php**

No import changes needed — `Message` is still used for `Message::create()` in the publish step; keep it. No `use AMQP10\Messaging\InboundMessage;` needed since the variable is untyped.

Replace:
```php
if ($delivery !== null) {
    echo "Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
```
With:
```php
if ($delivery !== null) {
    echo "Received: " . $delivery->body() . "\n";
    $delivery->accept();
```

- [ ] **Step 3: Update examples/consuming/03-batched-consumer.php**

Remove `use AMQP10\Messaging\Delivery;` import.

Add:
```php
use AMQP10\Messaging\InboundMessage;
```

Replace type hint in docblock:
```php
/** @var InboundMessage[] $batch */
```

Replace body of the foreach loop:
```php
foreach ($batch as $delivery) {
    echo "  -> " . $delivery->body() . "\n";
    $delivery->accept();
    $processed++;
}
```

- [ ] **Step 4: Update examples/consuming/04-stop-on-signal.php**

Replace imports:
```php
// Remove:
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;
// Add:
use AMQP10\Messaging\InboundMessage;
```

Replace handler:
```php
->handle(function (InboundMessage $msg) use (&$received): void {
    ++$received;
    echo "Received ($received): " . $msg->body() . "\n";
    $msg->accept();
})
```

- [ ] **Step 5: Update examples/consuming/05-stop-after-duration.php**

No import changes — `Message` is still used for `Message::create()`. Replace accessing patterns only:
```php
// Before:
$delivery->message()->body()   →   $delivery->body()
$delivery->context()->accept() →   $delivery->accept()
```

- [ ] **Step 6: Update examples/consuming/06-durable-subscription.php**

No import changes — `Message` is still used for `Message::create()`. Replace all occurrences:
```php
$delivery->message()->body()   →   $delivery->body()
$delivery->context()->accept() →   $delivery->accept()
```

- [ ] **Step 7: Update examples/streams/01-offset.php, 02-sql-filter.php, 03-bloom-filter.php**

No import changes in any of these — `Message` is still used for `Message::create()` in the publish loops. Replace accessing patterns only in each file:
```php
$delivery->message()->body()   →   $delivery->body()
$delivery->context()->accept() →   $delivery->accept()
```

- [ ] **Step 8: Run full unit suite one final time**

```bash
./vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass.

- [ ] **Step 9: Commit**

```bash
git add examples/
git commit -m "feat: update examples to use InboundMessage"
```

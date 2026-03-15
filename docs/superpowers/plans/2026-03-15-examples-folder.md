# Examples Folder Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create an `examples/` folder with 18 runnable PHP scripts covering all major use cases of the library.

**Architecture:** Each script is self-contained — it declares the queue/exchange it needs via management, demonstrates the feature, then cleans up. A shared `bootstrap.php` provides autoloader + `AMQP_URI` constant. Scripts are organised in five topic subfolders: `publishing/`, `consuming/`, `streams/`, `management/`, `advanced/`.

**Tech Stack:** PHP 8.2+, revolt/event-loop, RabbitMQ 4.0+. No test framework — verification is `php -l` (syntax check). Scripts must be run against a live broker to observe full behaviour.

**Spec:** `docs/superpowers/specs/2026-03-15-examples-folder-design.md`

---

## Chunk 1: Bootstrap and publishing

### Task 1: Bootstrap

**Files:**
- Create: `examples/bootstrap.php`

- [ ] **Step 1: Create bootstrap.php**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define('AMQP_URI', getenv('AMQP_URI') ?: 'amqp://guest:guest@localhost');
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/bootstrap.php`
Expected: `No syntax errors detected in examples/bootstrap.php`

- [ ] **Step 3: Commit**

```bash
git add examples/bootstrap.php
git commit -m "examples: add bootstrap with AMQP_URI constant"
```

---

### Task 2: Basic publish

**Files:**
- Create: `examples/publishing/01-basic-publish.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-basic-publish', QueueType::QUORUM));
$mgmt->close();

// Publish one message and check the broker accepted it.
$address = AddressHelper::queueAddress('example-basic-publish');
$outcome = $client->publish($address)->send(Message::create('Hello, AMQP 1.0!'));

echo $outcome->isAccepted() ? "Message accepted\n" : "Message not accepted\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-basic-publish');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/publishing/01-basic-publish.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/publishing/01-basic-publish.php
git commit -m "examples: add basic publish example"
```

---

### Task 3: Fire-and-forget publish

**Files:**
- Create: `examples/publishing/02-fire-and-forget.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-fire-and-forget', QueueType::QUORUM));
$mgmt->close();

// fireAndForget() uses pre-settled delivery mode: the broker does not send a
// disposition frame back. This gives higher throughput at the cost of no
// per-message delivery guarantee.
$address = AddressHelper::queueAddress('example-fire-and-forget');
$publisher = $client->publish($address)->fireAndForget();

for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Message $i"));
    echo "Sent message $i (no ack waited)\n";
}

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-fire-and-forget');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/publishing/02-fire-and-forget.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/publishing/02-fire-and-forget.php
git commit -m "examples: add fire-and-forget publish example"
```

---

### Task 4: Message properties

**Files:**
- Create: `examples/publishing/03-message-properties.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-message-properties', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-message-properties');

// Build a message with a rich set of properties.
$message = Message::create('{"event":"order.placed","orderId":42}')
    ->withMessageId('msg-001')
    ->withCorrelationId('req-abc')
    ->withSubject('order.placed')
    ->withContentType('application/json')
    ->withTtl(60_000)       // milliseconds
    ->withPriority(5)
    ->withApplicationProperty('tenant', 'acme')
    ->withApplicationProperty('region', 'eu-west');

$client->publish($address)->send($message);
echo "Published message with properties\n";

// Consume and read back to confirm round-trip.
// AMQP properties are read via property(key); application headers via applicationProperty(key).
$consumer = $client->consume($address)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    $msg = $delivery->message();
    echo "message-id:     " . $msg->property('message-id') . "\n";
    echo "correlation-id: " . $msg->property('correlation-id') . "\n";
    echo "subject:        " . ($msg->subject() ?? '(none)') . "\n";
    echo "content-type:   " . $msg->property('content-type') . "\n";
    echo "ttl:            " . $msg->ttl() . "\n";
    echo "priority:       " . $msg->priority() . "\n";
    echo "tenant:         " . $msg->applicationProperty('tenant') . "\n";
    echo "region:         " . $msg->applicationProperty('region') . "\n";
    echo "body:           " . $msg->body() . "\n";
    $delivery->context()->accept();
}

$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-message-properties');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/publishing/03-message-properties.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/publishing/03-message-properties.php
git commit -m "examples: add message properties example"
```

---

## Chunk 2: Consuming

### Task 5: Run loop

**Files:**
- Create: `examples/consuming/01-run-loop.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-run-loop', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-run-loop');
$total = 3;

// Publish 3 messages
$publisher = $client->publish($address);
for ($i = 1; $i <= $total; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published $total messages\n";

// Consume using run() with a handler closure.
//
// The handler receives (Message $message, DeliveryContext $ctx) — not a Delivery object.
// Call $ctx->accept() to acknowledge the delivery.
// Call $ctx->reject() instead to send the message to the dead-letter exchange.
//
// run() loops until receive() returns null (idle timeout, stop(), or disconnect).
// Without an explicit stop() call it will block for the full idleTimeout after the
// last message. Here we call $consumer->stop() once all expected messages are processed.
$count = 0;
$consumer = $client->consume($address)
    ->withIdleTimeout(2.0)
    ->credit($total)
    ->consumer();

$consumer->run(
    function (Message $message, DeliveryContext $ctx) use (&$count, $total, $consumer): void {
        $count++;
        echo "Received ($count/$total): " . $message->body() . "\n";
        $ctx->accept();
        // $ctx->reject(); // nack — routes to dead-letter exchange

        if ($count >= $total) {
            $consumer->stop();
        }
    }
);

echo "Run loop finished\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-run-loop');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/01-run-loop.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/01-run-loop.php
git commit -m "examples: add run loop consumer example"
```

---

### Task 6: One-shot receive

**Files:**
- Create: `examples/consuming/02-one-shot.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-one-shot', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-one-shot');

// Publish one message
$client->publish($address)->send(Message::create('Hello, one-shot!'));
echo "Published 1 message\n";

// Pull exactly one message using receive().
// receive() blocks until a message arrives or idleTimeout elapses, then returns null.
$consumer = $client->consume($address)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    echo "Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
} else {
    echo "No message received (queue empty or timeout)\n";
}

// Detach the consumer link before tearing down the queue.
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-one-shot');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/02-one-shot.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/02-one-shot.php
git commit -m "examples: add one-shot consumer example"
```

---

### Task 7: Batched consumer

**Files:**
- Create: `examples/consuming/03-batched-consumer.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Delivery;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-batched', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-batched');
$total = 20;

// Publish 20 messages
$publisher = $client->publish($address);
for ($i = 1; $i <= $total; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published $total messages\n";

// Batched consumer using a manual receive() loop.
//
// Two independent timeout mechanisms:
//   withIdleTimeout(1.0) — each receive() call blocks at most 1 second for a frame.
//   $batchDeadline       — wall-clock deadline for the current batch window.
//
// The batch is dispatched when it reaches $maxBatch OR the wall-clock deadline is reached.
// Consumer::run() is NOT used here — the manual receive() loop is the pattern.

$maxBatch = 10;
$batchWindowSecs = 5.0;
$processed = 0;
$batchNum = 0;

$consumer = $client->consume($address)
    ->withIdleTimeout(1.0)
    ->credit($total)
    ->consumer();

while ($processed < $total) {
    /** @var Delivery[] $batch */
    $batch = [];
    $batchDeadline = microtime(true) + $batchWindowSecs;

    while (count($batch) < $maxBatch && microtime(true) < $batchDeadline) {
        $delivery = $consumer->receive();
        if ($delivery === null) {
            break; // idle timeout — re-check wall-clock deadline
        }
        $batch[] = $delivery;
    }

    if (empty($batch)) {
        break; // nothing arrived in this window — queue drained
    }

    $batchNum++;
    echo "Batch $batchNum: processing " . count($batch) . " messages\n";

    foreach ($batch as $delivery) {
        echo "  -> " . $delivery->message()->body() . "\n";
        $delivery->context()->accept();
        $processed++;
    }
}

echo "Total processed: $processed\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-batched');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/03-batched-consumer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/03-batched-consumer.php
git commit -m "examples: add batched consumer example"
```

---

### Task 8: Stop on signal

**Files:**
- Create: `examples/consuming/04-stop-on-signal.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;

// Requires ext-pcntl for signal handling. Without it, stopOnSignal() is a no-op
// and the consumer will run until stopped by other means.
if (! extension_loaded('pcntl')) {
    echo "Warning: ext-pcntl not loaded — signal handling is disabled\n";
}

$client = (new Client(AMQP_URI))->connect();

// Setup — queue is not pre-seeded; publish messages separately to observe live consumption.
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stop-on-signal', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-stop-on-signal');
$received = 0;

echo "Consumer running. Publish a message to the queue, or press Ctrl+C to stop.\n";

// stopOnSignal() registers OS signal handlers via the revolt event loop.
// The optional closure receives the signal number as its only argument —
// use it for logging, cleanup, or status output before shutdown.
$client->consume($address)
    ->withIdleTimeout(1.0)
    ->handle(function (Message $message, DeliveryContext $ctx) use (&$received): void {
        $received++;
        echo "Received ($received): " . $message->body() . "\n";
        $ctx->accept();
    })
    ->stopOnSignal(
        [SIGINT, SIGTERM],
        function (int $signal): void {
            echo "\nCaught signal $signal — shutting down gracefully\n";
        }
    )
    ->run();

echo "Consumer stopped. Total received: $received\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stop-on-signal');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/04-stop-on-signal.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/04-stop-on-signal.php
git commit -m "examples: add stop-on-signal consumer example"
```

---

### Task 9: Stop after duration

**Files:**
- Create: `examples/consuming/05-stop-after-duration.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stop-after-duration', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-stop-after-duration');

// Pre-seed a few messages
$publisher = $client->publish($address);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Message $i"));
}

// Run for at most 30 seconds using a wall-clock deadline.
//
// withIdleTimeout(1.0) limits each receive() call to 1 second of blocking,
// so the wall-clock check fires responsively rather than waiting the full
// idleTimeout (default 30 s) between iterations.
$runForSeconds = 30.0;
$deadline = microtime(true) + $runForSeconds;
$received = 0;

$consumer = $client->consume($address)
    ->withIdleTimeout(1.0)
    ->credit(100)
    ->consumer();

echo "Consuming for up to {$runForSeconds}s (Ctrl+C to stop early)...\n";

while (microtime(true) < $deadline) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        continue; // idle timeout — loop back and re-check wall-clock
    }
    $received++;
    echo "Received ($received): " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Duration elapsed. Total received: $received\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stop-after-duration');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/05-stop-after-duration.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/05-stop-after-duration.php
git commit -m "examples: add stop-after-duration consumer example"
```

---

### Task 10: Durable subscription

**Files:**
- Create: `examples/consuming/06-durable-subscription.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Terminus\ExpiryPolicy;

// Durable subscriptions are most meaningful on stream queues, where the broker
// tracks the consumer's offset position across reconnects.
//
// durable()                    — defaults to TerminusDurability::UnsettledState;
//                                the broker remembers which messages have been settled.
// expiryPolicy(Never)          — keeps the subscription alive after detach.
// linkName('my-durable-sub')   — ties reconnects to the same subscription state.

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-durable-sub', QueueType::STREAM));
$mgmt->close();

// Stream and queue addresses differ for streams
$queueAddress  = AddressHelper::queueAddress('example-durable-sub');
$streamAddress = AddressHelper::streamAddress('example-durable-sub');
$linkName = 'my-durable-sub';

// Publish 6 messages
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 6; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published 6 messages\n";

// First session — consume 3, then disconnect
echo "--- First connection: consuming 3 messages ---\n";

$consumer = $client->consume($streamAddress)
    ->durable()                          // TerminusDurability::UnsettledState (default)
    ->expiryPolicy(ExpiryPolicy::Never)  // keep subscription alive after detach
    ->linkName($linkName)
    ->credit(3)
    ->consumer();

for ($i = 0; $i < 3; $i++) {
    $delivery = $consumer->receive();
    if ($delivery !== null) {
        echo "  Received: " . $delivery->message()->body() . "\n";
        $delivery->context()->accept();
    }
}
$consumer->close();
$client->close();
echo "Disconnected\n";

// Second session — reconnect and resume from where we left off
echo "--- Second connection: resuming ---\n";

$client2 = (new Client(AMQP_URI))->connect();

$consumer2 = $client2->consume($streamAddress)
    ->durable()
    ->expiryPolicy(ExpiryPolicy::Never)
    ->linkName($linkName)  // same link name = resume same subscription state
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

$received = 0;
while (true) {
    $delivery = $consumer2->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Resumed: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Resumed and received $received more messages\n";
$consumer2->close();

// Teardown
$mgmt = $client2->management();
$mgmt->deleteQueue('example-durable-sub');
$mgmt->close();
$client2->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/consuming/06-durable-subscription.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/consuming/06-durable-subscription.php
git commit -m "examples: add durable subscription example"
```

---

## Chunk 3: Streams, management, and advanced

### Task 11: Stream offset

**Files:**
- Create: `examples/streams/01-offset.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stream-offset', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-offset');
$streamAddress = AddressHelper::streamAddress('example-stream-offset');

// Publish 5 events
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Event $i"));
}
echo "Published 5 events\n";

// Consume from the beginning of the stream using Offset::first().
//
// Available offsets:
//   Offset::first()        — start of the stream
//   Offset::last()         — last message only
//   Offset::next()         — only new messages published after attach
//   Offset::offset(n)      — specific numeric offset
//   Offset::timestamp(ms)  — messages published at or after a Unix timestamp (ms)
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->withIdleTimeout(2.0)
    ->credit(5)
    ->consumer();

echo "Consuming from Offset::first()...\n";
$received = 0;
while (true) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Total received: $received\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-offset');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/streams/01-offset.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/streams/01-offset.php
git commit -m "examples: add stream offset example"
```

---

### Task 12: Stream SQL filter

**Files:**
- Create: `examples/streams/02-sql-filter.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stream-sql', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-sql');
$streamAddress = AddressHelper::streamAddress('example-stream-sql');

// Publish a mix of event types via application properties
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(
        Message::create("Order $i")->withApplicationProperty('type', 'order')
    );
    $publisher->send(
        Message::create("Notification $i")->withApplicationProperty('type', 'notification')
    );
}
echo "Published 10 messages (5 orders, 5 notifications)\n";

// filterSql() sends an AMQP SQL filter expression to the broker.
// Only messages matching the expression are delivered — filtering happens
// server-side, so non-matching messages are never transferred to the client.
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->filterSql("type = 'order'")
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

echo "Consuming with filterSql(\"type = 'order'\")...\n";
$received = 0;
while (true) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Total received: $received (expected 5)\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-sql');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/streams/02-sql-filter.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/streams/02-sql-filter.php
git commit -m "examples: add stream SQL filter example"
```

---

### Task 13: Stream Bloom filter

**Files:**
- Create: `examples/streams/03-bloom-filter.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stream-bloom', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-bloom');
$streamAddress = AddressHelper::streamAddress('example-stream-bloom');

// The annotation key 'x-stream-filter-value' is the RabbitMQ Bloom filter tag.
// Messages without this annotation are "unfiltered" — they can be received by passing
// matchUnfiltered: true as the second argument to filterBloom().
$publisher = $client->publish($queueAddress);

for ($i = 1; $i <= 3; $i++) {
    $publisher->send(
        Message::create("EU order $i")->withAnnotation('x-stream-filter-value', 'eu')
    );
}
for ($i = 1; $i <= 3; $i++) {
    $publisher->send(
        Message::create("US order $i")->withAnnotation('x-stream-filter-value', 'us')
    );
}
for ($i = 1; $i <= 2; $i++) {
    $publisher->send(Message::create("Untagged event $i")); // no filter annotation
}
echo "Published 8 messages (3 eu, 3 us, 2 untagged)\n";

// Consume only EU messages.
// Pass filterBloom(['eu', 'us']) to match multiple values.
// Pass true as second argument to also receive untagged messages.
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->filterBloom('eu')
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

echo "Consuming with filterBloom('eu')...\n";
$received = 0;
while (true) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Total received: $received (expected 3)\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-bloom');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/streams/03-bloom-filter.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/streams/03-bloom-filter.php
git commit -m "examples: add stream Bloom filter example"
```

---

### Task 14: Declare queues

**Files:**
- Create: `examples/management/01-declare-queue.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Quorum queue — recommended for most workloads.
// Replicated across nodes, durable, no data loss on broker failure.
$mgmt->declareQueue(new QueueSpecification('example-quorum', QueueType::QUORUM));
echo "Declared quorum queue\n";

// Stream queue — append-only log, supports offset-based consumption,
// SQL filters, and Bloom filters. Messages are retained after acknowledgement.
$mgmt->declareQueue(new QueueSpecification('example-stream', QueueType::STREAM));
echo "Declared stream queue\n";

// Classic queue — legacy type. Use quorum queues for new workloads.
$mgmt->declareQueue(new QueueSpecification('example-classic', QueueType::CLASSIC));
echo "Declared classic queue\n";

// Cleanup
$mgmt->deleteQueue('example-quorum');
$mgmt->deleteQueue('example-stream');
$mgmt->deleteQueue('example-classic');
echo "Deleted all queues\n";

$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/management/01-declare-queue.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/management/01-declare-queue.php
git commit -m "examples: add declare queue example"
```

---

### Task 15: Declare exchange and bind

**Files:**
- Create: `examples/management/02-declare-exchange-and-bind.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\BindingSpecification;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Declare a direct exchange
$mgmt->declareExchange(new ExchangeSpecification('example-exchange', ExchangeType::DIRECT));
echo "Declared direct exchange\n";

// Declare a quorum queue
$mgmt->declareQueue(new QueueSpecification('example-bound-queue', QueueType::QUORUM));
echo "Declared quorum queue\n";

// Bind the queue to the exchange with a routing key.
// Messages published to the exchange with routing key 'orders' are routed to this queue.
$mgmt->bind(new BindingSpecification('example-exchange', 'example-bound-queue', 'orders'));
echo "Bound queue to exchange with routing key 'orders'\n";

$mgmt->close();

// Publish to the exchange using the routing key
$exchangeAddress = AddressHelper::exchangeAddress('example-exchange', 'orders');
$outcome = $client->publish($exchangeAddress)->send(Message::create('Routed message'));
echo "Published to exchange: " . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";

// Consume from the queue to confirm routing worked
$queueAddress = AddressHelper::queueAddress('example-bound-queue');
$consumer = $client->consume($queueAddress)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    echo "Received via exchange routing: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-bound-queue');
$mgmt->deleteExchange('example-exchange');
echo "Cleaned up\n";
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/management/02-declare-exchange-and-bind.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/management/02-declare-exchange-and-bind.php
git commit -m "examples: add declare exchange and bind example"
```

---

### Task 16: Delete resources

**Files:**
- Create: `examples/management/03-delete.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Client\Client;
use AMQP10\Exception\ExchangeNotFoundException;
use AMQP10\Exception\QueueNotFoundException;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Declare resources to delete
$mgmt->declareQueue(new QueueSpecification('example-delete-queue', QueueType::QUORUM));
$mgmt->declareExchange(new ExchangeSpecification('example-delete-exchange', ExchangeType::DIRECT));
echo "Resources created\n";

// Delete them
$mgmt->deleteQueue('example-delete-queue');
echo "Deleted queue\n";

$mgmt->deleteExchange('example-delete-exchange');
echo "Deleted exchange\n";

// Attempting to delete a resource that does not exist throws a typed exception.
// Catch QueueNotFoundException / ExchangeNotFoundException for graceful handling.
try {
    $mgmt->deleteQueue('nonexistent-queue');
} catch (QueueNotFoundException $e) {
    echo "Queue not found (expected): " . $e->getMessage() . "\n";
}

try {
    $mgmt->deleteExchange('nonexistent-exchange');
} catch (ExchangeNotFoundException $e) {
    echo "Exchange not found (expected): " . $e->getMessage() . "\n";
}

$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/management/03-delete.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/management/03-delete.php
git commit -m "examples: add management delete example"
```

---

### Task 17: TLS connection

**Files:**
- Create: `examples/advanced/01-tls.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

// withTlsOptions() returns a new Client clone (immutable config pattern).
// connect() returns the same instance, now connected.
//
// Common TLS options (PHP stream context keys):
//   cafile           — path to CA certificate file (PEM)
//   local_cert       — path to client certificate for mutual TLS (PEM)
//   local_pk         — path to client private key
//   verify_peer      — verify server certificate (default: true)
//   verify_peer_name — verify server hostname (default: true)
//
// Use an amqps:// URI. RabbitMQ default AMQPS port is 5671.
// Set AMQP_URI=amqps://guest:guest@localhost:5671 to run this example.

// If AMQP_URI is already amqps://, use it as-is; otherwise swap the scheme.
$tlsUri = str_starts_with(AMQP_URI, 'amqps://')
    ? AMQP_URI
    : 'amqps://' . substr(AMQP_URI, strlen('amqp://'));

$client = (new Client($tlsUri))
    ->withTlsOptions([
        'cafile'      => '/path/to/ca.pem',  // replace with your CA certificate path
        'verify_peer' => true,
    ])
    ->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-tls', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-tls');
$outcome = $client->publish($address)->send(Message::create('Hello over TLS!'));
echo "Published over TLS: " . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-tls');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/advanced/01-tls.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/advanced/01-tls.php
git commit -m "examples: add TLS connection example"
```

---

### Task 18: Reconnecting publisher

**Files:**
- Create: `examples/advanced/02-reconnecting.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

// withReconnect() wraps each send() in a retry loop.
// On ConnectionFailedException or RuntimeException it will:
//   1. Wait backoffMs * attempt milliseconds
//   2. Reconnect the client
//   3. Re-attach the sender link
//   4. Retry the send
//
// maxRetries: maximum reconnect attempts per send call (0 = no retry)
// backoffMs:  base backoff in milliseconds, multiplied by the attempt count
//
// To test: run this script, then stop and restart RabbitMQ while it's publishing.

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-reconnect', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-reconnect');
$publisher = $client->publish($address)->withReconnect(maxRetries: 10, backoffMs: 500);

for ($i = 1; $i <= 20; $i++) {
    $outcome = $publisher->send(Message::create("Message $i"));
    echo "Sent $i: " . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";
    usleep(500_000); // 500 ms between sends — gives time to observe broker restarts
}

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-reconnect');
$mgmt->close();
$client->close();
```

- [ ] **Step 2: Lint check**

Run: `php -l examples/advanced/02-reconnecting.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add examples/advanced/02-reconnecting.php
git commit -m "examples: add reconnecting publisher example"
```

---

### Task 19: Lint all examples

- [ ] **Step 1: Run lint across all example files**

Run: `find examples -name '*.php' | xargs php -l`
Expected: `No syntax errors detected` for each file

- [ ] **Step 2: Final commit if any fixes were needed**

```bash
git add examples/
git commit -m "examples: fix any lint issues"
```

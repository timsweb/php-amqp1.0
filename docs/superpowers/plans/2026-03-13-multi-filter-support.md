# Multi-Filter Support Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add support for three filter types to ConsumerBuilder: JMS SQL selectors (ActiveMQ/Artemis), RabbitMQ AMQP SQL filters, and RabbitMQ Bloom filters. Update documentation to fix incorrect filter type references.

**Architecture:** Add three new methods to ConsumerBuilder (filterJms, filterAmqpSql, filterBloom), update Consumer to encode all three filter types using correct AMQP 1.0 descriptors, add comprehensive unit and integration tests.

**Tech Stack:** PHP 8.1+, PHPUnit, AMQP 1.0 protocol, RabbitMQ 4.x streams

---

## File Structure

Files to be modified (no new files):

**Core Implementation:**
- `src/AMQP10/Messaging/ConsumerBuilder.php` - Add filterJms(), filterAmqpSql(), filterBloom() methods, update filterSql() to map to RabbitMQ SQL
- `src/AMQP10/Messaging/Consumer.php` - Update constructor to accept 4 new filter parameters, update buildFilterMap() to encode all filter types

**Test Files:**
- `tests/Unit/Messaging/ConsumerTest.php` - Add tests for filterJms, filterAmqpSql, filterBloom (single/multiple), matchUnfiltered, and combined filters
- `tests/Integration/StreamFilterIntegrationTest.php` - Add integration tests for RabbitMQ SQL and Bloom filters

**Documentation:**
- `README.md` - Update consumer configuration examples, correct filter type documentation, add filter type reference table

---

## Background: Filter Encoding Reference

### AMQP 1.0 Filter Descriptors

| Filter Type | Descriptor Key | Value Encoding | Protocol | Use Case |
|-------------|----------------|----------------|------------|-----------|
| JMS SQL Selector | `apache.org:selector-filter:string` | Raw UTF-8 string | AMQP 1.0 | ActiveMQ/Artemis classic/quorum queues |
| RabbitMQ AMQP SQL | `amqp:sql-filter` | Described type: descriptor + UTF-8 string | AMQP 1.0 | RabbitMQ streams |
| RabbitMQ Bloom (single) | `rabbitmq:stream-filter` | Raw UTF-8 string | AMQP 1.0 | RabbitMQ streams |
| RabbitMQ Bloom (multiple) | `rabbitmq:stream-filter` | Symbol array | AMQP 1.0 | RabbitMQ streams |
| matchUnfiltered | `rabbitmq:stream-match-unfiltered` | Boolean | AMQP 1.0 | RabbitMQ streams |
| Offset | `rabbitmq:stream-offset-spec` | Described type: descriptor + offset value | AMQP 1.0 | RabbitMQ streams |

### Wire Format Details

**JMS SQL Selector (apache.org:selector-filter:string):**
```php
// Not wrapped in described type
$descriptor = TypeEncoder::encodeSymbol('apache.org:selector-filter:string');
$value = TypeEncoder::encodeString($sql);
$map[$descriptor] = $value;  // Raw string value
```

**RabbitMQ AMQP SQL (amqp:sql-filter):**
```php
// Must be a described type per AMQP 1.0 spec
$descriptor = TypeEncoder::encodeSymbol('amqp:sql-filter');
$sqlString = TypeEncoder::encodeString($sql);
$value = TypeEncoder::encodeDescribed($descriptor, $sqlString);
$map[$descriptor] = $value;  // Described type
```

**RabbitMQ Bloom Filter (rabbitmq:stream-filter):**
```php
// Single value: raw string
$descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-filter');
$value = TypeEncoder::encodeString($filterValue);
$map[$descriptor] = $value;

// Multiple values: symbol array
$descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-filter');
$symbols = array_map(fn($v) => TypeEncoder::encodeSymbol($v), $filterValues);
$value = TypeEncoder::encodeSymbolArray($symbols);
$map[$descriptor] = $value;
```

**matchUnfiltered (rabbitmq:stream-match-unfiltered):**
```php
$descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-match-unfiltered');
$value = TypeEncoder::encodeBool(true);
$map[$descriptor] = $value;
```

### API Design Decision: filterSql() Mapping

**Decision:** `filterSql()` maps to RabbitMQ AMQP SQL (not JMS)

**Rationale:**
- RabbitMQ is the primary target for this library
- Most users will use `filterSql()` for RabbitMQ streams
- JMS users can use explicit `filterJms()` method
- Simplifies common case while keeping full control available

**Behavior:**
```php
$client->consume('/streams/orders')
    ->filterSql("priority > 5")  // Maps to filterAmqpSql()
    ->run();

$client->consume('/queues/test')
    ->filterJms("priority > 5")  // Explicit for JMS
    ->run();
```

---

## Chunk 1: Update ConsumerBuilder API

This chunk adds three new filter methods to ConsumerBuilder and updates filterSql() mapping.

### Task 1.1: Add new filter properties to ConsumerBuilder

**File:** `src/AMQP10/Messaging/ConsumerBuilder.php`

- [ ] **Step 1: Read current ConsumerBuilder.php**
```bash
cat /Users/tim/Documents/projects/php-amqp10/src/AMQP10/Messaging/ConsumerBuilder.php
```

- [ ] **Step 2: Add new filter properties**

Add after line 13 (after existing `$filterSql`):
```php
private ?string $filterJms = null;
private ?string $filterAmqpSql = null;
private ?array $filterBloomValues = null;
private bool $matchUnfiltered = false;
```

- [ ] **Step 3: Update filterSql() to map to RabbitMQ SQL**

Replace lines 50-54 with:
```php
public function filterSql(string $sql): self
{
    // Maps to RabbitMQ AMQP SQL for streams (primary use case)
    return $this->filterAmqpSql($sql);
}
```

- [ ] **Step 4: Add filterJms() method**

Add after filterSql() method:
```php
public function filterJms(string $sql): self
{
    $this->filterJms = $sql;
    return $this;
}
```

- [ ] **Step 5: Add filterAmqpSql() method**

Add after filterJms() method:
```php
public function filterAmqpSql(string $sql): self
{
    $this->filterAmqpSql = $sql;
    return $this;
}
```

- [ ] **Step 6: Add filterBloom() method**

Add after filterAmqpSql() method:
```php
public function filterBloom(string|array $values, bool $matchUnfiltered = false): self
{
    $this->filterBloomValues = is_array($values) ? $values : [$values];
    $this->matchUnfiltered = $matchUnfiltered;
    return $this;
}
```

- [ ] **Step 7: Update consumer() method to pass new filters**

Replace lines 62-72 with:
```php
public function consumer(): Consumer
{
    return new Consumer(
        $this->session,
        $this->address,
        $this->credit,
        $this->offset,
        $this->filterJms,
        $this->filterAmqpSql,
        $this->filterBloomValues,
        $this->matchUnfiltered,
        $this->idleTimeout,
    );
}
```

**Verification:**
```bash
# Check syntax
php -l src/AMQP10/Messaging/ConsumerBuilder.php

# Check all methods exist
grep -E "public function (filterJms|filterAmqpSql|filterBloom)" src/AMQP10/Messaging/ConsumerBuilder.php
```

---

## Chunk 2: Update Consumer Implementation

This chunk updates Consumer to accept new filter parameters and encode them correctly.

### Task 2.1: Update Consumer constructor

**File:** `src/AMQP10/Messaging/Consumer.php`

- [ ] **Step 1: Read current Consumer.php**
```bash
cat /Users/tim/Documents/projects/php-amqp10/src/AMQP10/Messaging/Consumer.php
```

- [ ] **Step 2: Add new filter parameters to constructor**

Replace lines 18-24 with:
```php
public function __construct(
    private readonly Session  $session,
    string                   $address,
    int                      $credit       = 10,
    private readonly ?Offset  $offset           = null,
    private readonly ?string  $filterJms        = null,
    private readonly ?string  $filterAmqpSql    = null,
    private readonly ?array   $filterBloomValues = null,
    private readonly bool     $matchUnfiltered   = false,
    private readonly float    $idleTimeout      = 30.0,
) {
```

### Task 2.2: Update buildFilterMap() method

- [ ] **Step 3: Update null check in buildFilterMap()**

Replace line 38 with:
```php
if ($this->offset === null &&
    $this->filterJms === null &&
    $this->filterAmqpSql === null &&
    $this->filterBloomValues === null) {
    return null;
}
```

- [ ] **Step 4: Add JMS SQL filter encoding**

Add after line 55 (after offset filter):
```php
if ($this->filterJms !== null) {
    $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
        TypeEncoder::encodeString($this->filterJms);
}
```

- [ ] **Step 5: Replace existing filterSql encoding with filterAmqpSql**

Replace lines 57-60 with:
```php
if ($this->filterAmqpSql !== null) {
    $descriptor = TypeEncoder::encodeSymbol('amqp:sql-filter');
    $sqlString = TypeEncoder::encodeString($this->filterAmqpSql);
    $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $sqlString);
}
```

- [ ] **Step 6: Add RabbitMQ Bloom filter encoding**

Add after filterAmqpSql block:
```php
if ($this->filterBloomValues !== null) {
    $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-filter');
    if (count($this->filterBloomValues) === 1) {
        $value = TypeEncoder::encodeString($this->filterBloomValues[0]);
    } else {
        $symbols = array_map(fn($v) => TypeEncoder::encodeSymbol($v), $this->filterBloomValues);
        $value = TypeEncoder::encodeSymbolArray($symbols);
    }
    $pairs[$descriptor] = $value;
}
```

- [ ] **Step 7: Add matchUnfiltered encoding**

Add after filterBloom block:
```php
if ($this->matchUnfiltered) {
    $pairs[TypeEncoder::encodeSymbol('rabbitmq:stream-match-unfiltered')] =
        TypeEncoder::encodeBool(true);
}
```

**Verification:**
```bash
# Check syntax
php -l src/AMQP10/Messaging/Consumer.php

# Run existing unit tests to ensure no regression
php vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

---

## Chunk 3: Add Unit Tests

This chunk adds comprehensive unit tests for all filter types.

### Task 3.1: Test filterJms encoding

**File:** `tests/Unit/Messaging/ConsumerTest.php`

- [ ] **Step 1: Read current tests**
```bash
cat /Users/tim/Documents/projects/php-amqp10/tests/Unit/Messaging/ConsumerTest.php | tail -n 50
```

- [ ] **Step 2: Add test_buildFilterMap_with_filterJms()**

Add after line 314 (after test_buildFilterMap_with_filterSql):
```php
public function test_buildFilterMap_with_filterJms(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer($session, '/queues/test', credit: 1, filterJms: "color = 'red'");

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(1, $map);

    $key = array_key_first($map);
    $value = $map[$key];

    // Raw string value — not wrapped in a described type (JMS selector)
    $this->assertSame('apache.org:selector-filter:string', $key);
    $this->assertSame("color = 'red'", $value);
}
```

### Task 3.2: Test filterAmqpSql encoding

- [ ] **Step 3: Add test_buildFilterMap_with_filterAmqpSql()**

Add after filterJms test:
```php
public function test_buildFilterMap_with_filterAmqpSql(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer($session, '/queues/test', credit: 1, filterAmqpSql: "priority > 5");

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(1, $map);

    $key = array_key_first($map);
    $value = $map[$key];

    // Described type: descriptor + string value
    $this->assertIsArray($value);
    $this->assertSame('amqp:sql-filter', $value['descriptor']);
    $this->assertSame('priority > 5', $value['value']);
}
```

### Task 3.3: Test filterBloom single value

- [ ] **Step 4: Add test_buildFilterMap_with_filterBloom_single()**

Add after filterAmqpSql test:
```php
public function test_buildFilterMap_with_filterBloom_single(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer($session, '/queues/test', credit: 1, filterBloomValues: ['invoices']);

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(1, $map);

    $key = array_key_first($map);
    $value = $map[$key];

    // Single value: raw string
    $this->assertSame('rabbitmq:stream-filter', $key);
    $this->assertSame('invoices', $value);
}
```

### Task 3.4: Test filterBloom multiple values

- [ ] **Step 5: Add test_buildFilterMap_with_filterBloom_multiple()**

Add after filterBloom single test:
```php
public function test_buildFilterMap_with_filterBloom_multiple(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer($session, '/queues/test', credit: 1, filterBloomValues: ['california', 'texas', 'newyork']);

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(1, $map);

    $key = array_key_first($map);
    $value = $map[$key];

    // Multiple values: symbol array
    $this->assertSame('rabbitmq:stream-filter', $key);
    $this->assertIsArray($value);
    $this->assertContains('california', $value);
    $this->assertContains('texas', $value);
    $this->assertContains('newyork', $value);
}
```

### Task 3.5: Test matchUnfiltered

- [ ] **Step 6: Add test_buildFilterMap_with_matchUnfiltered()**

Add after filterBloom multiple test:
```php
public function test_buildFilterMap_with_matchUnfiltered(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer($session, '/queues/test', credit: 1, matchUnfiltered: true);

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(1, $map);

    $this->assertArrayHasKey('rabbitmq:stream-match-unfiltered', $map);
    $this->assertTrue($map['rabbitmq:stream-match-unfiltered']);
}
```

### Task 3.6: Test multiple combined filters

- [ ] **Step 7: Add test_buildFilterMap_with_multiple_filters()**

Add after matchUnfiltered test:
```php
public function test_buildFilterMap_with_multiple_filters(): void
{
    [$mock, $session] = $this->makeSession();

    $consumer = new Consumer(
        $session,
        '/queues/test',
        credit: 1,
        offset: Offset::offset(10),
        filterAmqpSql: "priority > 4",
        filterBloomValues: ['urgent', 'high-priority'],
        matchUnfiltered: true
    );

    $reflection = new \ReflectionClass($consumer);
    $method = $reflection->getMethod('buildFilterMap');
    $method->setAccessible(true);

    $result = $method->invoke($consumer);

    $this->assertNotNull($result);

    $decoder = new TypeDecoder($result);
    $map = $decoder->decode();

    $this->assertIsArray($map);
    $this->assertCount(4, $map);

    $this->assertArrayHasKey('rabbitmq:stream-offset-spec', $map);
    $this->assertArrayHasKey('amqp:sql-filter', $map);
    $this->assertArrayHasKey('rabbitmq:stream-filter', $map);
    $this->assertArrayHasKey('rabbitmq:stream-match-unfiltered', $map);
}
```

### Task 3.7: Test filterSql backward compatibility

- [ ] **Step 8: Add test_filterSql_maps_to_filterAmqpSql()**

Add after multiple filters test:
```php
public function test_filterSql_maps_to_filterAmqpSql(): void
{
    [$mock, $session] = $this->makeSession();

    $builder = new ConsumerBuilder($session, '/queues/test');
    $builder->filterSql("priority > 5");

    $consumer = $builder->consumer();

    $reflection = new \ReflectionClass($consumer);
    $filterAmqpSqlProperty = $reflection->getProperty('filterAmqpSql');
    $filterAmqpSqlProperty->setAccessible(true);
    $filterJmsProperty = $reflection->getProperty('filterJms');
    $filterJmsProperty->setAccessible(true);

    // filterSql() should map to filterAmqpSql, not filterJms
    $this->assertSame("priority > 5", $filterAmqpSqlProperty->getValue($consumer));
    $this->assertNull($filterJmsProperty->getValue($consumer));
}
```

**Verification:**
```bash
# Run new unit tests
php vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php --filter='/buildFilterMap|filterSql'
```

---

## Chunk 4: Add Integration Tests

This chunk adds integration tests for RabbitMQ filter functionality.

### Task 4.1: Test RabbitMQ AMQP SQL filter

**File:** `tests/Integration/StreamFilterIntegrationTest.php`

- [ ] **Step 1: Read current integration test**
```bash
cat /Users/tim/Documents/projects/php-amqp10/tests/Integration/StreamFilterIntegrationTest.php | tail -n 80
```

- [ ] **Step 2: Replace skipped test with working AMQP SQL test**

Replace lines 115-170 (test_consume_from_stream_with_filterSql) with:
```php
public function test_consume_from_stream_with_filterAmqpSql(): void
{
    $mgmt = $this->client->management();
    $queueName = $this->queueName . '-filter-amqp';

    try {
        try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
    } catch (\AMQP10\Exception\ManagementException $e) {
        $this->markTestSkipped('RabbitMQ stream plugin not available');
    }
    $mgmt->close();

    $address = AddressHelper::queueAddress($queueName);

    // Publish messages with different properties
    for ($i = 1; $i <= 10; $i++) {
        $msg = new Message("msg-{$i}");
        if ($i <= 3) {
            $msg->setSubject("priority-high");  // Match priority > 4
        } else {
            $msg->setSubject("priority-low");
        }
        $this->client->publish($address)->send($msg);
    }

    $received = [];
    $count = 0;
    $client = $this->client;

    set_time_limit(15);

    // Filter for high priority messages only
    $this->client->consume($address)
        ->credit(10)
        ->filterAmqpSql("properties.subject = 'priority-high'")  // RabbitMQ AMQP SQL
        ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
            use (&$received, &$count, $client) {
            $received[] = $msg->body();
            $ctx->accept();
            $count++;
            if ($count >= 3) {
                $client->close();
            }
        })
        ->run();

    // Should receive only 3 high-priority messages
    $this->assertCount(3, $received);
    $this->assertContains('msg-1', $received);
    $this->assertContains('msg-2', $received);
    $this->assertContains('msg-3', $received);
    $this->assertNotContains('msg-4', $received);
    $this->assertNotContains('msg-5', $received);

    $cleanup = $this->newClient()->connect();
    $cleanup->management()->deleteQueue($queueName);
    $cleanup->management()->close();
    $cleanup->close();
}
```

### Task 4.2: Test RabbitMQ Bloom filter

- [ ] **Step 3: Add test_consume_from_stream_with_filterBloom()**

Add after AMQP SQL test:
```php
public function test_consume_from_stream_with_filterBloom(): void
{
    $mgmt = $this->client->management();
    $queueName = $this->queueName . '-filter-bloom';

    try {
        try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
    } catch (\AMQP10\Exception\ManagementException $e) {
        $this->markTestSkipped('RabbitMQ stream plugin not available');
    }
    $mgmt->close();

    $address = AddressHelper::queueAddress($queueName);

    // Publish messages with x-stream-filter-value annotation
    $messages = ['invoices-1', 'orders-1', 'invoices-2', 'other-1', 'invoices-3'];
    foreach ($messages as $msg) {
        $message = new Message($msg);
        // Set filter value based on message content
        if (str_starts_with($msg, 'invoices')) {
            $message->addAnnotation('x-stream-filter-value', 'invoices');
        } elseif (str_starts_with($msg, 'orders')) {
            $message->addAnnotation('x-stream-filter-value', 'orders');
        }
        $this->client->publish($address)->send($message);
    }

    $received = [];
    $count = 0;
    $client = $this->client;

    set_time_limit(15);

    // Filter for 'invoices' only using Bloom filter
    $this->client->consume($address)
        ->credit(10)
        ->filterBloom(['invoices'])  // RabbitMQ Bloom filter
        ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
            use (&$received, &$count, $client) {
            $received[] = $msg->body();
            $ctx->accept();
            $count++;
            if ($count >= 3) {
                $client->close();
            }
        })
        ->run();

    // Should receive only 3 invoice messages (Bloom filter is probabilistic, but likely matches)
    $this->assertCount(3, $received);
    $this->assertContains('invoices-1', $received);
    $this->assertContains('invoices-2', $received);
    $this->assertContains('invoices-3', $received);
    $this->assertNotContains('orders-1', $received);
    $this->assertNotContains('other-1', $received);

    $cleanup = $this->newClient()->connect();
    $cleanup->management()->deleteQueue($queueName);
    $cleanup->management()->close();
    $cleanup->close();
}
```

### Task 4.3: Test combined filters

- [ ] **Step 4: Add test_consume_from_stream_with_combined_filters()**

Add after Bloom filter test:
```php
public function test_consume_from_stream_with_combined_filters(): void
{
    $mgmt = $this->client->management();
    $queueName = $this->queueName . '-combined';

    try {
        try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
    } catch (\AMQP10\Exception\ManagementException $e) {
        $this->markTestSkipped('RabbitMQ stream plugin not available');
    }
    $mgmt->close();

    $address = AddressHelper::queueAddress($queueName);

    // Publish messages with different filters and priorities
    for ($i = 1; $i <= 20; $i++) {
        $msg = new Message("msg-{$i}");

        if ($i % 5 === 1) {
            $msg->addAnnotation('x-stream-filter-value', 'high-priority');
            $msg->setSubject('urgent');
        } elseif ($i % 5 === 2) {
            $msg->addAnnotation('x-stream-filter-value', 'high-priority');
            $msg->setSubject('normal');
        } elseif ($i % 5 === 3) {
            $msg->addAnnotation('x-stream-filter-value', 'other');
            $msg->setSubject('urgent');
        } else {
            $msg->addAnnotation('x-stream-filter-value', 'other');
            $msg->setSubject('normal');
        }

        $this->client->publish($address)->send($msg);
    }

    $received = [];
    $count = 0;
    $client = $this->client;

    set_time_limit(15);

    // Combine Bloom filter + AMQP SQL + offset
    $this->client->consume($address)
        ->credit(10)
        ->offset(Offset::offset(5))  // Start from offset 5
        ->filterBloom(['high-priority'])  // Bloom filter
        ->filterAmqpSql("properties.subject = 'urgent'")  // AMQP SQL filter
        ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
            use (&$received, &$count, $client) {
            $received[] = $msg->body();
            $ctx->accept();
            $count++;
            if ($count >= 1) {
                $client->close();
            }
        })
        ->run();

    // Should receive at least one message (msg-6 or msg-11 or msg-16, after offset 5)
    // that matches both Bloom filter (high-priority) and AMQP SQL (urgent)
    $this->assertGreaterThanOrEqual(1, count($received));
    $this->assertGreaterThanOrEqual(6, (int) str_replace('msg-', '', $received[0] ?? 'msg-0'));

    $cleanup = $this->newClient()->connect();
    $cleanup->management()->deleteQueue($queueName);
    $cleanup->management()->close();
    $cleanup->close();
}
```

**Verification:**
```bash
# Run integration tests (requires RabbitMQ with stream plugin)
php vendor/bin/phpunit tests/Integration/StreamFilterIntegrationTest.php
```

---

## Chunk 5: Update Documentation

This chunk updates README.md with correct filter type documentation and usage examples.

### Task 5.1: Update Consumer Configuration section

**File:** `README.md`

- [ ] **Step 1: Read current README consumer section**
```bash
sed -n '230,250p' /Users/tim/Documents/projects/php-amqp10/README.md
```

- [ ] **Step 2: Replace filter documentation**

Replace lines 232-247 with:
```php
### Consumer Configuration

```php
$client->consume('my-queue')
    ->credit(10)                  // Flow control credit (prefetch)
    ->prefetch(10)                // Alias for credit()
    ->offset(Offset::offset(100))   // Start from offset 100 (stream queues only)
    // Filter methods:
    ->filterSql('priority > 5')          // RabbitMQ AMQP SQL (streams only) - shortcut for filterAmqpSql()
    ->filterAmqpSql('priority > 5')       // RabbitMQ AMQP SQL (streams only) - explicit
    ->filterJms('priority > 5')           // JMS SQL selector (ActiveMQ/Artemis only)
    ->filterBloom('value')                // RabbitMQ Bloom filter (streams only)
    ->filterBloom(['invoices', 'orders'], matchUnfiltered: true)  // Multiple values + match unfiltered
    ->handle(function ($msg, $ctx) {
        // Handle message
        $ctx->accept();
    })
    ->run();
```

**Filter Types:**
- `filterSql()` / `filterAmqpSql()` - RabbitMQ AMQP SQL filter expression (streams only)
- `filterJms()` - JMS SQL selector (ActiveMQ/Artemis classic/quorum queues only)
- `filterBloom()` - RabbitMQ Bloom filter (streams only)

**Note:** `filterSql()` is a convenience method that maps to `filterAmqpSql()` for RabbitMQ streams. For JMS SQL selectors (ActiveMQ/Artemis), use the explicit `filterJms()` method.
```

### Task 5.2: Add filter type reference section

- [ ] **Step 3: Add filter type reference table**

Find a good location after Consumer Configuration section and add:
```markdown
## Filter Types Reference

| Filter Method | Descriptor Key | Encoding | Broker Support | Use Case |
|---------------|----------------|------------|----------------|-----------|
| `filterSql()` / `filterAmqpSql()` | `amqp:sql-filter` | Described type | RabbitMQ 4.x streams | Server-side SQL filtering with RabbitMQ AMQP SQL syntax |
| `filterJms()` | `apache.org:selector-filter:string` | Raw string | ActiveMQ/Artemis (JMS brokers) | JMS SQL selector syntax |
| `filterBloom()` | `rabbitmq:stream-filter` | String (single) or Symbol array (multiple) | RabbitMQ 4.x streams | Efficient chunk-level filtering with Bloom filter |
| `matchUnfiltered` (via `filterBloom()`) | `rabbitmq:stream-match-unfiltered` | Boolean | RabbitMQ 4.x streams | Include messages without filter value in Bloom filter |

**Filter Details:**

**RabbitMQ AMQP SQL (`filterAmqpSql()`):**
- Supports SQL WHERE clause syntax on message sections (header, properties, application-properties)
- Examples: `priority > 4`, `properties.subject = 'order'`, `region IN ('AMER', 'EMEA')`
- Reference: [RabbitMQ Stream Filtering - Stage 2: AMQP Filter Expressions](https://www.rabbitmq.com/docs/stream-filtering#stage-2-amqp-filter-expressions)

**JMS SQL Selector (`filterJms()`):**
- Uses Apache JMS selector syntax
- Examples: `priority > 5`, `color = 'red'`, `region = 'EMEA' AND priority > 3`
- Only works with JMS-compliant brokers (ActiveMQ, Artemis)
- Does NOT work with RabbitMQ

**RabbitMQ Bloom Filter (`filterBloom()`):**
- Highly efficient chunk-level filtering (Stage 1)
- Publishers set `x-stream-filter-value` annotation on messages
- Consumers filter by matching filter values
- Single value: string; Multiple values: logically OR'd together
- `matchUnfiltered: true` - also receive messages without filter value
- Can combine with AMQP SQL filter for efficient 2-stage filtering
- Reference: [RabbitMQ Stream Filtering - Stage 1: Bloom Filter](https://www.rabbitmq.com/docs/stream-filtering#stage-1-bloom-filter)
```

**Verification:**
```bash
# Check README syntax
php -l README.md

# Verify documentation renders correctly
# (Manual verification in Markdown viewer)
```

---

## Chunk 6: Verify Implementation

This chunk runs all tests to ensure implementation is complete and correct.

### Task 6.1: Run all unit tests

- [ ] **Step 1: Run Consumer unit tests**
```bash
cd /Users/tim/Documents/projects/php-amqp10
php vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

- [ ] **Step 2: Verify all new tests pass**
```bash
# Should see all tests pass including new filter tests
php vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php --testdox
```

### Task 6.2: Run integration tests

- [ ] **Step 3: Run integration tests (requires RabbitMQ)**
```bash
php vendor/bin/phpunit tests/Integration/StreamFilterIntegrationTest.php
```

**Note:** Integration tests require RabbitMQ 4.x with stream plugin enabled. If RabbitMQ is not available, these tests will be skipped.

### Task 6.3: Run full test suite

- [ ] **Step 4: Run complete test suite**
```bash
php vendor/bin/phpunit
```

- [ ] **Step 5: Verify no regressions**
```bash
# All existing tests should still pass
php vendor/bin/phpunit --testdox | grep -E "(PASS|FAIL)"
```

---

## Validation Checklist

Upon completion, verify:

- [ ] `filterSql()` maps to `filterAmqpSql()` (RabbitMQ SQL)
- [ ] `filterJms()` exists and sets JMS selector
- [ ] `filterAmqpSql()` exists and sets RabbitMQ AMQP SQL
- [ ] `filterBloom()` exists and supports single value (string) and multiple values (array)
- [ ] `filterBloom()` supports `matchUnfiltered` parameter
- [ ] JMS filter encodes with `apache.org:selector-filter:string` key and raw string value
- [ ] RabbitMQ AMQP SQL filter encodes with `amqp:sql-filter` key and described type
- [ ] Bloom filter single value encodes with `rabbitmq:stream-filter` key and raw string
- [ ] Bloom filter multiple values encodes with `rabbitmq:stream-filter` key and symbol array
- [ ] `matchUnfiltered` encodes with `rabbitmq:stream-match-unfiltered` key and boolean true
- [ ] All three filter types work independently
- [ ] Multiple filters can be combined (offset + JMS + RabbitMQ SQL + Bloom)
- [ ] Unit tests cover all filter types and combinations
- [ ] Integration tests verify end-to-end functionality with RabbitMQ
- [ ] README updated with correct filter type documentation
- [ ] README documentation includes filter type reference table
- [ ] All existing tests continue to pass (no regression)
- [ ] New tests follow existing test patterns and conventions

---

## References

- [RabbitMQ Stream Filtering Documentation](https://www.rabbitmq.com/docs/stream-filtering)
- [AMQP 1.0 Filter Expressions Spec](https://docs.oasis-open.org/amqp/filtex/v1.0/csd01/filtex-v1.0-csd01.html)
- [AMQP 1.0 Core Specification](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-overview-v1.0-os.html)
- [AMQP 1.0 Type System](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-types-v1.0-os.html)
- RabbitMQ Java Client Examples: https://github.com/rabbitmq/rabbitmq-amqp-java-client

---

## Success Criteria

Implementation is complete when:

1. ✅ ConsumerBuilder has three new filter methods (filterJms, filterAmqpSql, filterBloom)
2. ✅ filterSql() maps to filterAmqpSql() for RabbitMQ streams
3. ✅ Consumer encodes all three filter types correctly using appropriate descriptors
4. ✅ Unit tests verify each filter type encoding independently
5. ✅ Unit tests verify multiple filter combinations
6. ✅ Integration tests demonstrate RabbitMQ AMQP SQL and Bloom filtering
7. ✅ README documentation correctly describes all three filter types
8. ✅ README includes filter type reference table
9. ✅ All existing tests pass (no regression)
10. ✅ Code follows existing conventions and patterns

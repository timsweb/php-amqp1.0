# AMQP 1.0 Client for PHP

Modern PHP AMQP 1.0 client library for RabbitMQ 4.0+ with a fluent, type-safe API.

## Requirements

- PHP 8.2 or higher
- RabbitMQ 4.0+
- revolt/event-loop ^1.0

## Installation

```bash
composer require php-amqp10/client
```

## Basic Usage

### Connecting

The client uses [Revolt](https://revolt.run/) Fibers for non-blocking I/O. For one-off operations (publishing during a web request, running a script) **no event loop boilerplate is needed** — the library handles it transparently:

```php
$client = new \AMQP10\Client\Client('amqp://guest:guest@localhost:5672');
$client->connect();

// ... use the client ...

$client->close();
```

Long-running consumers manage the event loop internally via `ConsumerBuilder::run()` — see [Consuming Messages](#consuming-messages).

If you are already running a [Revolt](https://revolt.run/) or [Amp](https://amphp.org/) event loop, the client integrates naturally — just call it from within your existing loop context.

### TLS Connection

```php
$client = (new \AMQP10\Client\Client('amqps://user:pass@broker.example.com:5671'))
    ->withTlsOptions(['cafile' => '/path/to/ca.pem'])
    ->connect();
```

### Virtual Hosts

```php
// Explicit vhost via URI path
$client = new \AMQP10\Client\Client('amqp://user:pass@host/my-vhost');

// RabbitMQ default vhost (/) using URL encoding
$client = new \AMQP10\Client\Client('amqp://user:pass@host/%2F');
```

### Publishing Messages

Publishing works directly — no event loop setup required:

```php
use AMQP10\Client\Client;
use AMQP10\Messaging\Message;

$client = new Client('amqp://guest:guest@localhost:5672');
$client->connect();

$outcome = $client->publish('/queues/my-queue')
    ->send(Message::create('Hello, world!'));

if ($outcome->isAccepted()) {
    echo "Message accepted\n";
}

$client->close();
```

### Message API — Factory and Wither Methods

```php
use AMQP10\Messaging\Message;

$message = Message::create('{"orderId": 123}')
    ->withSubject('order.placed')
    ->withContentType('application/json')
    ->withMessageId('msg-abc-123')
    ->withCorrelationId('order-123')
    ->withApplicationProperty('source', 'checkout')
    ->withTtl(30000)     // TTL in milliseconds
    ->withDurable(true); // default is true
```

### Fire-and-Forget Publishing (Pre-settled)

Pre-settled messages are sent without waiting for a broker disposition — highest throughput mode:

```php
$client->publish('/exchanges/events')
    ->fireAndForget()
    ->send(Message::create($payload)->withSubject('order.placed'));
```

### Consuming Messages

`ConsumerBuilder::run()` blocks until the consumer exits, managing the event loop internally. Use `stopOnSignal()` for graceful shutdown:

```php
use AMQP10\Client\Client;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\DeliveryContext;

$client = new Client('amqp://guest:guest@localhost:5672');
$client->connect();

$client->consume('/queues/my-queue')
    ->handle(function (Message $msg, DeliveryContext $ctx) {
        echo "Received: " . $msg->body() . "\n";
        $ctx->accept();
    })
    ->stopOnSignal([SIGINT, SIGTERM])  // Ctrl+C stops cleanly after current message
    ->run();                          // blocks here

$client->close();
```

The optional second argument to `stopOnSignal()` is a callback fired when the signal arrives — useful for logging or console output:

```php
$client->consume('/queues/my-queue')
    ->handle(fn(Message $msg, DeliveryContext $ctx) => $ctx->accept())
    ->stopOnSignal([SIGINT, SIGTERM], function (int $signal) use ($output) {
        $output->writeln('<comment>Signal ' . $signal . ' received, finishing current message...</comment>');
    })
    ->run();
```

> **Note:** `stopOnSignal()` requires the `ext-pcntl` PHP extension. Without it, signal registration is silently skipped and the consumer runs until it times out or the connection closes.

### Consuming with Prefetch

```php
$client->consume('/queues/my-queue')
    ->prefetch(10) // Allow up to 10 unacknowledged messages in flight
    ->handle(function (Message $msg, DeliveryContext $ctx) {
        processMessage($msg);
        $ctx->accept();
    })
    ->stopOnSignal(SIGINT)
    ->run();
```

### Durable Consumer (Survives Reconnect)

A durable consumer with a stable link name will resume from where it left off after reconnection:

```php
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;

$client->consume('/queues/orders')
    ->linkName('order-processor')                    // stable link name survives reconnect
    ->durable(TerminusDurability::UnsettledState)
    ->expiryPolicy(ExpiryPolicy::Never)
    ->withReconnect(maxRetries: 10, backoffMs: 1000)
    ->handle(function (Message $msg, DeliveryContext $ctx) {
        // process message
        $ctx->accept();
    })
    ->stopOnSignal([SIGINT, SIGTERM])
    ->run();
```

## Message Context

When consuming messages, the delivery context provides methods to control message acknowledgment:

```php
$client->consume('/queues/my-queue')
    ->handle(function (Message $msg, DeliveryContext $ctx) {
        try {
            processMessage($msg);
            $ctx->accept();  // Acknowledge successful processing
        } catch (\Exception $e) {
            $ctx->release(); // Return message to queue for redelivery
            // OR
            // $ctx->reject(); // Reject without redelivery
        }
    })
    ->run();
```

### Modified Outcome (Dead-lettering / Retry Elsewhere)

```php
// Retry on a different consumer (dead-letter on failure elsewhere)
$ctx->modify(deliveryFailed: true, undeliverableHere: false); // retry elsewhere

// Dead-letter the message
$ctx->modify(deliveryFailed: true, undeliverableHere: true);  // dead-letter
```

## Management API

### Declare and Manage Queues

```php
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = new Client('amqp://guest:guest@localhost:5672');
$client->connect();

$mgmt = $client->management();

// Declare a classic queue
$spec = new QueueSpecification('my-queue', QueueType::CLASSIC);
$mgmt->declareQueue($spec);

// Declare a quorum queue
$spec = new QueueSpecification(
    name: 'my-durable-queue',
    type: QueueType::QUORUM,
);
$mgmt->declareQueue($spec);

// Delete a queue
$mgmt->deleteQueue('my-queue');

$client->close();
```

### Declare and Manage Exchanges

```php
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;

$mgmt = $client->management();

// Declare a direct exchange
$spec = new ExchangeSpecification('my-exchange', ExchangeType::DIRECT);
$mgmt->declareExchange($spec);

// Declare a fanout exchange
$spec = new ExchangeSpecification('my-fanout', ExchangeType::FANOUT);
$mgmt->declareExchange($spec);

// Declare a topic exchange
$spec = new ExchangeSpecification('my-topic', ExchangeType::TOPIC);
$mgmt->declareExchange($spec);

// Delete an exchange
$mgmt->deleteExchange('my-exchange');
```

### Bind Queues to Exchanges

```php
use AMQP10\Management\BindingSpecification;

$mgmt = $client->management();

$binding = new BindingSpecification(
    sourceExchange: 'my-exchange',
    destinationQueue: 'my-queue',
    bindingKey: 'routing-key',
);
$mgmt->bind($binding);
```

## Advanced Configuration

### Custom SASL Authentication

```php
use AMQP10\Client\Client;
use AMQP10\Connection\Sasl;

// PLAIN authentication (default)
$client = (new Client('amqp://localhost:5672/'))
    ->withSasl(Sasl::plain('username', 'password'))
    ->connect();

// EXTERNAL authentication
$client = (new Client('amqp://localhost:5672/'))
    ->withSasl(Sasl::external())
    ->connect();
```

### Consumer Configuration Reference

```php
$client->consume('/queues/my-queue')
    ->credit(10)                  // Flow control credit (prefetch)
    ->prefetch(10)                // Alias for credit()
    ->linkName('my-consumer')     // Stable link name for durable consumers
    ->durable(TerminusDurability::UnsettledState) // Durable subscription
    ->expiryPolicy(ExpiryPolicy::Never)           // Never expire the subscription
    ->withReconnect(maxRetries: 10, backoffMs: 1000) // Reconnect on failure
    ->offset(Offset::offset(100))   // Start from offset 100 (stream queues only)
    // Filter methods:
    ->filterSql('priority > 5')          // RabbitMQ AMQP SQL (streams only) - shortcut for filterAmqpSql()
    ->filterAmqpSql('priority > 5')      // RabbitMQ AMQP SQL (streams only) - explicit
    ->filterJms('priority > 5')          // JMS SQL selector (ActiveMQ/Artemis only)
    ->filterBloom('value')               // RabbitMQ Bloom filter (streams only)
    ->filterBloom(['invoices', 'orders'], matchUnfiltered: true)  // Multiple values + match unfiltered
    ->handle(function (Message $msg, DeliveryContext $ctx) {
        $ctx->accept();
    })
    ->onError(function (\Throwable $error) {
        error_log("Consumer error: " . $error->getMessage());
    })
    ->stopOnSignal([SIGINT, SIGTERM])                     // Graceful shutdown on signal (requires ext-pcntl)
    ->stopOnSignal([SIGINT, SIGTERM], fn(int $sig) => log($sig))  // With custom callback
    ->run();
```

**Filter Types:**
- `filterSql()` / `filterAmqpSql()` - RabbitMQ AMQP SQL filter expression (streams only)
- `filterJms()` - JMS SQL selector (ActiveMQ/Artemis classic/quorum queues only)
- `filterBloom()` - RabbitMQ Bloom filter (streams only)

**Note:** `filterSql()` is a convenience method that maps to `filterAmqpSql()` for RabbitMQ streams. For JMS SQL selectors (ActiveMQ/Artemis), use the explicit `filterJms()` method.

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

## Address Format

The library uses RabbitMQ AMQP 1.0 address format:

```php
// Queue address
$address = '/queues/my-queue';

// Topic exchange with routing key
$address = '/exchanges/amq.topic/orders.created';

// Custom exchange with routing key
$address = '/exchanges/my-exchange/routing-key';
```

## Error Handling

```php
use AMQP10\Client\Client;
use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\AmqpException;
use AMQP10\Messaging\Message;

$client = new Client('amqp://guest:guest@localhost:5672');

try {
    $client->connect();
    $client->publish('/queues/my-queue')->send(Message::create('test'));
} catch (AuthenticationException $e) {
    echo "Authentication failed: " . $e->getMessage() . "\n";
} catch (AmqpException $e) {
    echo "AMQP error: " . $e->getMessage() . "\n";
} finally {
    $client->close();
}
```

## Running Tests

```bash
# Install dependencies
composer install

# Run unit tests
./vendor/bin/phpunit

# Run integration tests (starts RabbitMQ automatically via testcontainers — requires Docker)
./vendor/bin/phpunit --testsuite Integration
```

## License

Apache-2.0

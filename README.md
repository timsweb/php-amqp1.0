# AMQP 1.0 Client for PHP

Modern PHP AMQP 1.0 client library for RabbitMQ 4.0+ with a fluent, type-safe API.

## Requirements

- PHP 8.2 or higher
- RabbitMQ 4.0+

## Installation

```bash
composer require php-amqp10/client
```

## Basic Usage

### Connecting

```php
use AMQP10\Client\Client;

$client = new Client('amqp://guest:guest@localhost:5672/');
$client->connect();

// ... work with the client ...

$client->close();
```

### Publishing Messages

```php
use AMQP10\Client\Client;
use AMQP10\Messaging\Message;

$client = new Client('amqp://guest:guest@localhost:5672/');
$client->connect();

$address = 'my-queue';
$message = new Message('Hello, world!');

$outcome = $client->publish($address)->send($message);

if ($outcome->isAccepted()) {
    echo "Message accepted\n";
}

$client->close();
```

### Publishing with Properties

```php
use AMQP10\Client\Client;
use AMQP10\Messaging\Message;

$client = new Client('amqp://guest:guest@localhost:5672/');
$client->connect();

$message = new Message(
    body: 'Hello, world!',
    properties: [
        'content-type'   => 'text/plain',
        'correlation-id' => '12345',
    ],
    applicationProperties: [
        'priority' => 'high',
    ],
    ttl: 60000, // 60 seconds
    priority: 8,
);

$client->publish('my-queue')->send($message);

$client->close();
```

### Consuming Messages

```php
use AMQP10\Client\Client;

$client = new Client('amqp://guest:guest@localhost:5672/');
$client->connect();

$address = 'my-queue';
$received = null;

$client->consume($address)
    ->credit(1)
    ->handle(function ($msg, $ctx) use (&$received, $client) {
        $received = $msg->body();
        echo "Received: " . $msg->body() . "\n";
        
        // Access message properties
        echo "Content-Type: " . $msg->property('content-type') . "\n";
        echo "Correlation-ID: " . $msg->applicationProperty('correlation-id') . "\n";
        
        // Acknowledge or reject the message
        $ctx->accept();
        // OR
        // $ctx->reject();
        // OR
        // $ctx->release();
        
        // Stop consuming after receiving one message
        $client->close();
    })
    ->run();

echo "Final message: " . $received . "\n";
```

### Consuming with Prefetch

```php
$client->consume('my-queue')
    ->prefetch(10) // Allow up to 10 unacknowledged messages in flight
    ->handle(function ($msg, $ctx) {
        // Process message
        processMessage($msg);
        $ctx->accept();
    })
    ->run();
```

## Management API

### Declare and Manage Queues

```php
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = new Client('amqp://guest:guest@localhost:5672/');
$client->connect();

$mgmt = $client->management();

// Declare a classic queue
$spec = new QueueSpecification('my-queue', QueueType::CLASSIC);
$mgmt->declareQueue($spec);

// Declare a queue with options
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
    source: 'my-exchange',
    destination: 'my-queue',
    bindingKey: 'routing-key',
);
$mgmt->bind($binding);
```

## Advanced Configuration

### Auto-Reconnect

```php
use AMQP10\Client\Client;

$client = new Client('amqp://guest:guest@localhost:5672/')
    ->withAutoReconnect(
        maxRetries: 5,    // Maximum number of reconnection attempts
        backoffMs: 1000,  // Delay between retries in milliseconds
    )
    ->connect();
```

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
    ->onError(function ($error) {
        // Handle errors
        error_log("Consumer error: " . $error->getMessage());
    })
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

## Message Context

When consuming messages, the delivery context provides methods to control message acknowledgment:

```php
$client->consume('my-queue')
    ->handle(function ($msg, $ctx) {
        try {
            processMessage($msg);
            $ctx->accept(); // Acknowledge successful processing
        } catch (\Exception $e) {
            $ctx->release(); // Return message to queue for redelivery
            // OR
            // $ctx->reject(); // Reject without redelivery
        }
    })
    ->run();
```

## Address Format

The library uses AMQP 1.0 address format:

```php
// Queue address
$address = 'my-queue';

// Topic exchange
$address = 'amq.topic/orders.created';

// Custom exchange with routing key
$address = 'my-exchange/routing-key';
```

## Error Handling

```php
use AMQP10\Client\Client;
use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\AmqpException;

try {
    $client = new Client('amqp://guest:guest@localhost:5672/');
    $client->connect();
    
    $client->publish('my-queue')->send(new Message('test'));
    
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

# Run integration tests (requires running RabbitMQ 4.0+ with AMQP 1.0)
./vendor/bin/phpunit --configuration phpunit-integration.xml
```

## License

Apache-2.0

# AMQP 1.0 Client for PHP

Modern PHP AMQP 1.0 client library for RabbitMQ 4.0+ with a fluent, type-safe API.

## Requirements

- PHP 8.1 or higher
- RabbitMQ 4.0+ with AMQP 1.0 plugin enabled

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
        'content-type' => 'text/plain',
    ],
    applicationProperties: [
        'correlation-id' => '12345',
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
    ->prefetch(10) // Process up to 10 messages in parallel
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

$mgmt->close();
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

$mgmt->close();
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

$mgmt->close();
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
$sasl = Sasl::plain('username', 'password');
$client = new Client('amqp://localhost:5672/', sasl: $sasl);

// ANONYMOUS authentication
$sasl = Sasl::anonymous();
$client = new Client('amqp://localhost:5672/', sasl: $sasl);

$client->connect();
```

### Consumer Configuration

```php
$client->consume('my-queue')
    ->credit(10)               // Flow control credit (prefetch)
    ->prefetch(10)             // Alias for credit()
    ->offset(new Offset(100))  // Start from offset 100
    ->filterSql('priority > 5') // Filter messages with SQL-92
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

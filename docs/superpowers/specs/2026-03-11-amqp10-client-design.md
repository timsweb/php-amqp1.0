# PHP AMQP 1.0 Client Library Design

**Date:** 2026-03-11
**Status:** Draft

## Overview

A modern PHP 8.1+ client library for AMQP 1.0 messaging with RabbitMQ 4.0+, designed to provide an excellent developer experience through fluent APIs while properly modeling AMQP 1.0 concepts (links, sessions, connections, exchanges, queues, bindings).

## Design Goals

1. **Developer Experience:** Simple, fluent API that eliminates boilerplate
2. **AMQP 1.0 Compliance:** Properly model protocol concepts (connections, sessions, links, addresses)
3. **Wide Runtime Support:** Support for blocking, ReactPHP, Swoole, and AMPHP via adapter pattern
4. **Reliability:** Optional auto-reconnection with configurable backoff
5. **Testability:** Unit-testable without requiring live RabbitMQ instances

## Architecture

### Layered Architecture

```
┌─────────────────────────────────────┐
│     API Layer (Fluent Interface)    │
├─────────────────────────────────────┤
│     Client Layer                   │
│  - Connection, Session, Links       │
├─────────────────────────────────────┤
│     Transport Layer                │
│  - Runtime Adapters               │
├─────────────────────────────────────┤
│     Protocol Layer                 │
│  - AMQP 1.0 Frame Encoding       │
└─────────────────────────────────────┘
```

### Design Principles

- **Adapters** abstract async runtime differences (ReactPHP, Swoole, AMPHP, blocking)
- **Protocol implementation** is runtime-agnostic
- **Fluent API** wraps the client layer, not a replacement
- **Optional features** (auto-reconnect, connection pool) are composable

## Component Structure

```
AMQP10\
├── Client\                 # Main client interface
│   ├── Client.php
│   └── Config.php
├── Management\              # Topology management
│   ├── ManagementInterface.php
│   ├── ExchangeSpecification.php
│   ├── QueueSpecification.php
│   └── BindingSpecification.php
├── Messaging\              # Message handling
│   ├── Message.php
│   ├── Publisher.php
│   ├── Consumer.php
│   └── Outcome.php
├── Connection\            # Connection handling
│   ├── Connection.php
│   ├── Sasl.php
│   └── AutoReconnect.php
├── Address\               # Address handling
│   ├── AddressHelper.php
│   └── Address.php
└── Transport\             # Runtime adapters
    ├── TransportInterface.php
    ├── BlockingAdapter.php
    ├── ReactAdapter.php
    ├── SwooleAdapter.php
    └── AmpAdapter.php
```

## API Design

### Core Client Interface

```php
namespace AMQP10\Client;

class Client
{
    public static function connect(string $uri, Config $config = null): static;
    public static function connectWithAdapter(string $uri, TransportInterface $adapter): static;

    public function withAutoReconnect(int $maxRetries = 5, int $backoffMs = 1000): static;
    public function withSasl(Sasl $sasl): static;

    public function management(): Management\ManagementInterface;
    public function publish(string $address): Messaging\PublisherBuilder;
    public function consume(string $address): Messaging\ConsumerBuilder;
    public function close(): void;
}
```

### Management API

Topology declaration and management for exchanges, queues, and bindings.

```php
$management = $client->management();

// Declare exchange
$management->declareExchange(
    new Management\ExchangeSpecification(
        name: 'my-exchange',
        type: ExchangeType::DIRECT
    )
);

// Declare queue
$management->declareQueue(
    new Management\QueueSpecification(
        name: 'my-queue',
        type: QueueType::QUORUM,
        durable: true
    )
);

// Bind exchange to queue
$management->bind(
    new Management\BindingSpecification(
        sourceExchange: 'my-exchange',
        destinationQueue: 'my-queue',
        bindingKey: 'my-routing-key'
    )
);

// Cleanup
$management->deleteQueue('my-queue');
$management->deleteExchange('my-exchange');
```

### Publishing

Send messages to exchanges using AMQP 1.0 address format.

```php
// Basic publish
$client->publish('/exchanges/my-exchange/my-routing-key')
    ->send(new Messaging\Message('hello world'));

// Publish with options
$client->publish('/exchanges/my-exchange/my-routing-key')
    ->ttl(60000)
    ->priority(5)
    ->send(new Messaging\Message('hello world', properties: ['content-type' => 'text/plain']));

// Send to queue (uses default exchange internally)
$client->publish('/queues/my-queue')
    ->send(new Messaging\Message('direct to queue'));
```

**Address formats (RabbitMQ v2):**
- `/exchanges/:exchange/:routing-key` - Send to exchange with routing key
- `/exchanges/:exchange` - Send to exchange (for fanout/headers exchanges)
- `/queues/:queue` - Send directly to queue

### Consuming

Receive messages from queues with callback handlers.

```php
// Basic consume
$client->consume('/queues/my-queue')
    ->handle(function (Messaging\Message $message, Messaging\DeliveryContext $ctx) {
        echo "Received: " . $message->body() . "\n";
        $ctx->accept();
    })
    ->run();

// With prefetch
$client->consume('/queues/my-queue')
    ->prefetch(10)
    ->handle(function ($msg, $ctx) {
        // Process message
        $ctx->accept();
    })
    ->run();

// With error handling
$client->consume('/queues/my-queue')
    ->handle(function ($msg, $ctx) {
        try {
            process($msg);
            $ctx->accept();
        } catch (Exception $e) {
            $ctx->reject(requeue: false);
        }
    })
    ->onError(function (Exception $e) {
        // Handle consumer errors
    })
    ->run();
```

**Delivery outcomes:**
- `accept()` - Message successfully processed (equivalent to AMQP 0.9.1 basic.ack)
- `reject(requeue: false)` - Invalid message, dead-letter (basic.nack)
- `requeue()` - Requeue message (basic.nack with requeue=true)

### Configuration

```php
use AMQP10\Client\Config;
use AMQP10\Connection\Sasl;

$config = new Config(
    autoReconnect: true,
    maxRetries: 5,
    backoffMs: 1000,
    sasl: Sasl::plain('user', 'pass'),
);

$client = Client::connect('amqp://guest:guest@localhost:5672/', $config);
```

**Fluent alternative:**
```php
$client = Client::connect('amqp://guest:guest@localhost:5672/')
    ->withAutoReconnect(maxRetries: 5, backoffMs: 1000)
    ->withSasl(Sasl::plain('user', 'pass'));
```

**URI schemes:**
- `amqp://` - Plain TCP
- `amqps://` - TLS encrypted
- Virtual host: `amqp://user:pass@vhost:tenant1/localhost:5672/`

## Data Flow

### Connection Establishment

1. Client connects via URI with SASL authentication
2. Protocol negotiation (AMQP 1.0 vs 0.9.1)
3. Connection opens with virtual host in hostname field (e.g., `vhost:tenant-1`)
4. Session created for multiplexing links

### Publishing Flow

1. Publisher created with target address
2. Link (sender) attached to exchange address
3. Message sent with properties (content-type, TTL, priority)
4. Await outcome (Accepted/Rejected/Released)
5. Publisher confirms settlement

### Consuming Flow

1. Consumer created with source address (queue)
2. Link (receiver) attached to queue address
3. Flow control negotiated (credit/grant)
4. Messages delivered via delivery tags
5. Application acknowledges (accept/reject/release/requeue)
6. Settlement completes

### Address Resolution

RabbitMQ implements AMQP 1.0 address format v2:

**Target addresses:**
- `/exchanges/:exchange/:routing-key` - Send to exchange with routing key
- `/exchanges/:exchange` - Send to exchange (empty routing key)
- `/queues/:queue` - Send to queue
- `null` - Dynamic routing per message (set in message `to` field)

**Source addresses:**
- `/queues/:queue` - Consume from queue

**Percent-encoding:** Special characters in exchange/queue/routing-key names must be percent-encoded per RFC 3986.

## Error Handling

### Exception Hierarchy

```php
namespace AMQP10\Exception;

abstract class AmqpException extends \Exception {}

class ConnectionException extends AmqpException
{
    class ConnectionFailedException extends ConnectionException {}
    class AuthenticationException extends ConnectionException {}
    class ConnectionClosedException extends ConnectionException {}
}

class MessagingException extends AmqpException
{
    class PublishException extends MessagingException {}
    class ConsumerException extends MessagingException {}
    class MessageTimeoutException extends MessagingException {}
}

class ManagementException extends AmqpException
{
    class ExchangeNotFoundException extends ManagementException {}
    class QueueNotFoundException extends ManagementException {}
    class BindingException extends ManagementException {}
}

class ProtocolException extends AmqpException
{
    class InvalidAddressException extends ProtocolException {}
    class FrameException extends ProtocolException {}
    class SaslException extends ProtocolException {}
}
```

### Error Scenarios

- **Connection failures:** Throw `ConnectionException`, auto-reconnect if enabled
- **Publish failures:** Return `Outcome` (Accepted/Rejected/Released), throw if configured
- **Consumer errors:** Throw `ConsumerException`, requeue/ack based on outcome
- **Invalid topology:** Throw `ManagementException` during declaration
- **Protocol errors:** Throw `ProtocolException` with frame details

### Auto-Reconnect Behavior

- Configurable max retries and exponential backoff
- Re-establish links (publishers/consumers) after reconnect
- Preserve message buffers where possible
- Notify application via event listener
- Node drain mode: Handled by `ConnectionClosedException`, auto-reconnect will retry

## Testing Strategy

### Unit-Only Approach

No integration tests requiring live RabbitMQ instances. All testing uses mocked transport layer.

### Test Structure

```
tests/
├── Unit/
│   ├── Address/
│   │   └── AddressHelperTest.php
│   ├── Management/
│   │   ├── ManagementInterfaceTest.php
│   │   └── SpecificationTest.php
│   ├── Messaging/
│   │   ├── PublisherTest.php
│   │   ├── ConsumerTest.php
│   │   └── MessageTest.php
│   └── Connection/
│       ├── SaslTest.php
│       ├── AutoReconnectTest.php
│       └── ConnectionTest.php
└── Mocks/
    ├── TransportMock.php
    ├── FrameBuilder.php
    └── BrokerSimulator.php
```

### Testing Approach

- **TransportMock:** Simulates AMQP frames without network
- **BrokerSimulator:** Generates realistic outcomes (Accepted/Rejected/Released)
- **FrameBuilder:** Constructs valid AMQP 1.0 frames for testing
- Test message encoding/decoding in isolation
- Simulate protocol states (connection, session, link)
- Mock broker responses for outcomes and errors

## Transport Adapters

### Transport Interface

```php
namespace AMQP10\Transport;

interface TransportInterface
{
    public function connect(string $uri): void;
    public function disconnect(): void;
    public function sendFrame(string $frame): void;
    public function receiveFrame(): ?string;
    public function isConnected(): bool;
}
```

### Supported Runtimes

1. **BlockingAdapter:** Synchronous PHP, blocking I/O
2. **ReactAdapter:** ReactPHP event loop
3. **SwooleAdapter:** Swoole coroutine-based async
4. **AmpAdapter:** AMPHP event loop

## Protocol Considerations

### AMQP 1.0 vs AMQP 0.9.1

This library implements **AMQP 1.0** only. Key differences from AMQP 0.9.1:

- **Links** instead of channels (more granular flow control)
- **Sessions** multiplex multiple links
- **Address-based** routing instead of exchange/queue separation
- **Outcomes** (Accepted/Rejected/Released/Modified) instead of ack/nack
- **Sender/Receiver** roles instead of publisher/consumer

### Message Format

AMQP 1.0 messages consist of:
- **Header** (delivery annotations, message annotations)
- **Properties** (content-type, content-encoding, TTL, priority, etc.)
- **Application properties** (custom headers)
- **Body** (one or more sections)

### Supported Features

- Exchanges: direct, fanout, topic, headers
- Queues: classic, quorum, stream
- Bindings with routing keys
- Message TTL and priority
- Publisher confirms (outcomes)
- Consumer acknowledgments (outcomes)
- Flow control (credit-based)
- Auto-reconnection
- TLS/SSL
- SASL authentication (PLAIN, EXTERNAL)

### Limitations

- No transaction support (not supported by RabbitMQ AMQP 1.0)
- No link recovery/suspension (not supported by RabbitMQ AMQP 1.0)

## Implementation Notes

### PHP Version

**Target:** PHP 8.1+

**Rationale:**
- Modern features: typed properties, readonly, first-class callables, named arguments
- Better performance and memory usage
- Cleaner code with modern syntax

### Dependencies

- No external protocol dependencies (AMQP 1.0 implementation from scratch)
- Optional runtime adapters: react/event-loop, swoole/async, amphp/amp

### Performance Considerations

- Connection pooling for high-throughput scenarios
- Efficient frame encoding/decoding
- Flow control to prevent memory bloat
- Binary message body support (no automatic serialization)

## Future Enhancements

**Potential future features:**
- Request-response pattern helpers
- Dead-letter queue utilities
- Stream protocol support
- Connection pool
- Metrics and observability hooks
- Distributed tracing integration
- Message compression

## References

- [RabbitMQ AMQP 1.0 Documentation](https://www.rabbitmq.com/docs/amqp)
- [AMQP 1.0 Specification (OASIS)](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-overview-v1.0-os.html)
- [RabbitMQ AMQP Python Client](https://github.com/rabbitmq/rabbitmq-amqp-python-client)
- [RabbitMQ AMQP Java Client](https://github.com/rabbitmq/rabbitmq-amqp-java-client)

# AMQP 1.0 PHP Client Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a modern PHP 8.1+ AMQP 1.0 client library for RabbitMQ 4.0+ with fluent APIs, wide runtime support, and excellent developer experience.

**Architecture:** Layered architecture with clear boundaries: API Layer → Client Layer → Transport Layer → Protocol Layer. Transport adapters abstract async runtime differences (ReactPHP, Swoole, AMPHP, blocking). Unit-only testing with mocked transport layer.

**Tech Stack:** PHP 8.1+, Composer, PHPUnit, no external protocol dependencies (AMQP 1.0 implementation from scratch)

---

## Chunk 1: Project Setup and Foundation

### Task 1.1: Initialize Project Structure

**Files:**
- Create: `composer.json`
- Create: `src/AMQP10/` (directory structure)
- Create: `tests/` (directory structure)

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "php-amqp10/client",
    "description": "Modern PHP AMQP 1.0 client library for RabbitMQ 4.0+",
    "type": "library",
    "license": "Apache-2.0",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "AMQP10\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "AMQP10\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Run composer install**

```bash
composer install
```

Expected: Composer creates `vendor/` directory and autoloader

- [ ] **Step 3: Create directory structure**

```bash
mkdir -p src/AMQP10/{Client,Management,Messaging,Connection,Address,Exception,Transport,Protocol}
mkdir -p tests/Unit/{Client,Management,Messaging,Connection,Address,Exception,Transport,Protocol}
mkdir -p tests/Mocks
```

- [ ] **Step 4: Commit**

```bash
git add composer.json src/ tests/
git commit -m "chore: initialize project structure and composer.json"
```

### Task 1.2: Exception Hierarchy

**Files:**
- Create: `src/AMQP10/Exception/AmqpException.php`
- Create: `src/AMQP10/Exception/ConnectionException.php`
- Create: `src/AMQP10/Exception/MessagingException.php`
- Create: `src/AMQP10/Exception/ManagementException.php`
- Create: `src/AMQP10/Exception/ProtocolException.php`

- [ ] **Step 1: Write failing test for base exception**

```php
// tests/Unit/Exception/AmqpExceptionTest.php
namespace AMQP10\Tests\Exception;

use AMQP10\Exception\AmqpException;
use PHPUnit\Framework\TestCase;

class AmqpExceptionTest extends TestCase
{
    public function test_exception_can_be_created_with_message(): void
    {
        $exception = new AmqpException('test message');
        $this->assertSame('test message', $exception->getMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Exception/AmqpExceptionTest.php
```

Expected: FAIL with "Class AMQP10\Exception\AmqpException not found"

- [ ] **Step 3: Write base exception implementation**

```php
// src/AMQP10/Exception/AmqpException.php
namespace AMQP10\Exception;

abstract class AmqpException extends \Exception
{
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Exception/AmqpExceptionTest.php
```

Expected: PASS

- [ ] **Step 5: Repeat for all exception classes**

Write tests and implementations for:
- `ConnectionException`, `ConnectionFailedException`, `AuthenticationException`, `ConnectionClosedException`
- `MessagingException`, `PublishException`, `ConsumerException`, `MessageTimeoutException`
- `ManagementException`, `ExchangeNotFoundException`, `QueueNotFoundException`, `BindingException`
- `ProtocolException`, `InvalidAddressException`, `FrameException`, `SaslException`

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Exception/ tests/Unit/Exception/
git commit -m "feat: add exception hierarchy with typed exceptions"
```

### Task 1.3: Configuration

**Files:**
- Create: `src/AMQP10/Client/Config.php`

- [ ] **Step 1: Write failing test for Config**

```php
// tests/Unit/Client/ConfigTest.php
namespace AMQP10\Tests\Client;

use AMQP10\Client\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function test_config_defaults(): void
    {
        $config = new Config();
        $this->assertFalse($config->autoReconnect);
        $this->assertSame(5, $config->maxRetries);
        $this->assertSame(1000, $config->backoffMs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Client/ConfigTest.php
```

Expected: FAIL

- [ ] **Step 3: Write Config implementation**

```php
// src/AMQP10/Client/Config.php
namespace AMQP10\Client;

use AMQP10\Connection\Sasl;

readonly class Config
{
    public function __construct(
        public bool $autoReconnect = false,
        public int $maxRetries = 5,
        public int $backoffMs = 1000,
        public ?Sasl $sasl = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Client/ConfigTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Client/Config.php tests/Unit/Client/ConfigTest.php
git commit -m "feat: add Config class with fluent configuration"
```

---

## Chunk 2: Protocol Layer - Types and Frame Structure

### Task 2.1: AMQP 1.0 Types

**Files:**
- Create: `src/AMQP10/Protocol/Types.php`
- Create: `src/AMQP10/Protocol/Frame.php`

- [ ] **Step 1: Write test for frame encoding**

```php
// tests/Unit/Protocol/FrameTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\Frame;
use AMQP10\Protocol\Types;
use PHPUnit\Framework\TestCase;

class FrameTest extends TestCase
{
    public function test_encode_performative_frame(): void
    {
        $frame = new Frame(Frame::PERFORMATIVE, 0x10, 0x00);
        $encoded = $frame->encode();

        $this->assertSame("\x02\x01\x00\x10\x00", $encoded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Frame class**

```php
// src/AMQP10/Protocol/Frame.php
namespace AMQP10\Protocol;

readonly class Frame
{
    public const PERFORMATIVE = 0x02;
    public const HEADER = 0x00;

    public function __construct(
        public readonly int $type,
        public readonly int $channel,
        public readonly int $performative,
    ) {}

    public function encode(): string
    {
        return pack('Cnn', $this->type, $this->channel, $this->performative);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/FrameTest.php
```

Expected: PASS

- [ ] **Step 5: Add tests for AMQP types**

Test for:
- Described types
- Primitive types (null, boolean, ubyte, ushort, uint, ulong, byte, short, int, long, float, double, decimal, timestamp, uuid, binary, string, symbol, list, map, array)
- Encoding/decoding of each type

- [ ] **Step 6: Implement Types class**

```php
// src/AMQP10/Protocol/Types.php
namespace AMQP10\Protocol;

class Types
{
    public const NULL = 0x40;
    public const BOOL = 0x56;
    public const UBYTE = 0x50;
    // ... add all AMQP 1.0 type codes

    public static function encodeNull(): string { return pack('C', self::NULL); }
    public static function encodeBool(bool $value): string { return pack('C', self::BOOL) . ($value ? "\x01" : "\x00"); }
    // ... implement all type encoders
}
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Protocol/ tests/Unit/Protocol/
git commit -m "feat: add AMQP 1.0 types and frame encoding"
```

### Task 2.2: Frame Encoder/Decoder

**Files:**
- Create: `src/AMQP10/Protocol/Encoder.php`
- Create: `src/AMQP10/Protocol/Decoder.php`

- [ ] **Step 1: Write test for encoder**

```php
// tests/Unit/Protocol/EncoderTest.php
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\Encoder;
use PHPUnit\Framework\TestCase;

class EncoderTest extends TestCase
{
    public function test_encode_string(): void
    {
        $encoded = Encoder::encodeString('test');

        $this->assertSame("\x81\x00\x00\x00S\x04test", $encoded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/EncoderTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Encoder class**

```php
// src/AMQP10/Protocol/Encoder.php
namespace AMQP10\Protocol;

class Encoder
{
    public static function encodeString(string $value): string
    {
        $length = strlen($value);
        return pack('Cna*', Types::STR, $length, $value);
    }

    public static function encodeUint(int $value): string
    {
        return pack('CN', Types::UINT, $value);
    }

    // ... implement all encoders
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/EncoderTest.php
```

Expected: PASS

- [ ] **Step 5: Add decoder tests**

Test for:
- Decoding all AMQP types
- Handling partial frames
- Error cases (invalid type codes)

- [ ] **Step 6: Implement Decoder class**

```php
// src/AMQP10/Protocol/Decoder.php
namespace AMQP10\Protocol;

class Decoder
{
    private int $offset = 0;
    private string $buffer = '';

    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    public function decodeString(): string
    {
        $type = $this->readByte();
        $length = $this->readUint();
        $value = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    // ... implement all decoders

    private function readByte(): int
    {
        return ord($this->buffer[$this->offset++]);
    }

    private function readUint(): int
    {
        $value = unpack('N', substr($this->buffer, $this->offset, 4))[1];
        $this->offset += 4;
        return $value;
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Protocol/ tests/Unit/Protocol/
git commit -m "feat: add frame encoder and decoder for AMQP 1.0"
```

---

## Chunk 3: Transport Layer

### Task 3.1: Transport Interface

**Files:**
- Create: `src/AMQP10/Transport/TransportInterface.php`

- [ ] **Step 1: Write test for transport contract**

```php
// tests/Unit/Transport/TransportInterfaceTest.php
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class TransportInterfaceTest extends TestCase
{
    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists(TransportInterface::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Transport/TransportInterfaceTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement TransportInterface**

```php
// src/AMQP10/Transport/TransportInterface.php
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

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Transport/TransportInterfaceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Transport/TransportInterface.php tests/Unit/Transport/
git commit -m "feat: add TransportInterface contract"
```

### Task 3.2: Blocking Adapter

**Files:**
- Create: `src/AMQP10/Transport/BlockingAdapter.php`

- [ ] **Step 1: Write test for blocking adapter**

```php
// tests/Unit/Transport/BlockingAdapterTest.php
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\BlockingAdapter;
use PHPUnit\Framework\TestCase;

class BlockingAdapterTest extends TestCase
{
    public function test_connect_socket(): void
    {
        // Will need to mock socket or use a test server
        $this->markTestIncomplete('Need socket mock');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Transport/BlockingAdapterTest.php
```

Expected: FAIL (or incomplete)

- [ ] **Step 3: Implement BlockingAdapter**

```php
// src/AMQP10/Transport/BlockingAdapter.php
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;

class BlockingAdapter implements TransportInterface
{
    private ?\Socket $socket = null;

    public function connect(string $uri): void
    {
        $parts = parse_url($uri);
        $this->socket = @stream_socket_client($parts['host'], $parts['port'], $errno, $errstr);

        if (!$this->socket) {
            throw new ConnectionFailedException("Failed to connect: $errstr");
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function sendFrame(string $frame): void
    {
        if (!$this->socket) {
            throw new ConnectionFailedException('Not connected');
        }

        fwrite($this->socket, $frame);
    }

    public function receiveFrame(): ?string
    {
        if (!$this->socket) {
            throw new ConnectionFailedException('Not connected');
        }

        $frame = fread($this->socket, 4096);
        return $frame === false ? null : $frame;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Transport/BlockingAdapterTest.php
```

Expected: PASS (or marked incomplete if mock not ready)

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Transport/BlockingAdapter.php tests/Unit/Transport/
git commit -m "feat: add BlockingAdapter for synchronous PHP"
```

### Task 3.3: Runtime Adapters

**Files:**
- Create: `src/AMQP10/Transport/ReactAdapter.php`
- Create: `src/AMQP10/Transport/SwooleAdapter.php`
- Create: `src/AMQP10/Transport/AmpAdapter.php`

- [ ] **Step 1: Write tests for each adapter**

```php
// tests/Unit/Transport/ReactAdapterTest.php
// tests/Unit/Transport/SwooleAdapterTest.php
// tests/Unit/Transport/AmpAdapterTest.php
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Transport/
```

Expected: FAIL

- [ ] **Step 3: Implement adapters**

```php
// src/AMQP10/Transport/ReactAdapter.php
namespace AMQP10\Transport;

use React\Socket\Connector;
use React\EventLoop\Loop;

class ReactAdapter implements TransportInterface
{
    private Connector $connector;
    private ?\React\Socket\ConnectionInterface $connection = null;

    public function __construct(private Loop $loop) {
        $this->connector = new Connector($loop);
    }

    public function connect(string $uri): void
    {
        // Parse URI and connect using ReactPHP
    }

    // ... implement other methods using ReactPHP async patterns
}
```

Repeat for Swoole and AMPHP adapters.

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Transport/
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Transport/ tests/Unit/Transport/
git commit -m "feat: add async adapters for ReactPHP, Swoole, AMPHP"
```

---

## Chunk 4: Connection Layer

### Task 4.1: SASL Authentication

**Files:**
- Create: `src/AMQP10/Connection/Sasl.php`

- [ ] **Step 1: Write test for SASL PLAIN**

```php
// tests/Unit/Connection/SaslTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Sasl;
use PHPUnit\Framework\TestCase;

class SaslTest extends TestCase
{
    public function test_plain_auth(): void
    {
        $sasl = Sasl::plain('user', 'pass');
        $this->assertSame('PLAIN', $sasl->mechanism());
        $this->assertSame("user\x00user\x00pass", $sasl->initialResponse());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SaslTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Sasl class**

```php
// src/AMQP10/Connection/Sasl.php
namespace AMQP10\Connection;

readonly class Sasl
{
    private function __construct(
        public readonly string $mechanism,
        public readonly string $initialResponse,
    ) {}

    public static function plain(string $username, string $password): self
    {
        return new self('PLAIN', "\x00" . $username . "\x00" . $password);
    }

    public static function external(): self
    {
        return new self('EXTERNAL', '');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SaslTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/Sasl.php tests/Unit/Connection/
git commit -m "feat: add SASL authentication support (PLAIN, EXTERNAL)"
```

### Task 4.2: Connection

**Files:**
- Create: `src/AMQP10/Connection/Connection.php`

- [ ] **Step 1: Write test for connection open**

```php
// tests/Unit/Connection/ConnectionTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Connection;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function test_connection_open(): void
    {
        $transport = new TransportMock();
        $connection = new Connection($transport, 'amqp://guest:guest@localhost:5672/');

        $this->assertFalse($connection->isOpen());
        $connection->open();
        $this->assertTrue($connection->isOpen());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Connection/ConnectionTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Connection class**

```php
// src/AMQP10/Connection/Connection.php
namespace AMQP10\Connection;

use AMQP10\Client\Config;
use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Protocol\Frame;
use AMQP10\Transport\TransportInterface;

class Connection
{
    private bool $open = false;

    public function __construct(
        private TransportInterface $transport,
        private readonly string $uri,
        private readonly Config $config = new Config(),
    ) {}

    public function open(): void
    {
        // Parse URI
        $parts = parse_url($this->uri);
        $host = $parts['host'];
        $port = $parts['port'] ?? 5672;
        $user = $parts['user'] ?? 'guest';
        $pass = $parts['pass'] ?? 'guest';

        // Connect transport
        $this->transport->connect("tcp://$host:$port");

        // Send protocol header (AMQP 1.0)
        $this->transport->sendFrame("AMQP\x01\x01\x00");

        // SASL authentication
        $sasl = $this->config->sasl ?? Sasl::plain($user, $pass);
        $this->authenticate($sasl);

        $this->open = true;
    }

    public function close(): void
    {
        if ($this->open) {
            $this->sendCloseFrame();
            $this->transport->disconnect();
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    private function authenticate(Sasl $sasl): void
    {
        // Implement SASL authentication flow
    }

    private function sendCloseFrame(): void
    {
        $frame = new Frame(Frame::PERFORMATIVE, 0, 0x18); // Close frame
        $this->transport->sendFrame($frame->encode());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Connection/ConnectionTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/ tests/Unit/Connection/
git commit -m "feat: add Connection class with AMQP 1.0 protocol negotiation"
```

### Task 4.3: Auto-Reconnect

**Files:**
- Create: `src/AMQP10/Connection/AutoReconnect.php`

- [ ] **Step 1: Write test for auto-reconnect**

```php
// tests/Unit/Connection/AutoReconnectTest.php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\AutoReconnect;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class AutoReconnectTest extends TestCase
{
    public function test_reconnect_on_failure(): void
    {
        $transport = new TransportMock(shouldFail: true);
        $autoReconnect = new AutoReconnect($transport, maxRetries: 2);

        $result = $autoReconnect->connect();

        $this->assertTrue($result->retried);
        $this->assertSame(2, $result->attempts);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Connection/AutoReconnectTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement AutoReconnect class**

```php
// src/AMQP10/Connection/AutoReconnect.php
namespace AMQP10\Connection;

use AMQP10\Transport\TransportInterface;
use AMQP10\Exception\ConnectionClosedException;

class AutoReconnect
{
    private int $attempts = 0;

    public function __construct(
        private TransportInterface $transport,
        private readonly int $maxRetries = 5,
        private readonly int $backoffMs = 1000,
    ) {}

    public function connectWithRetry(): ConnectionResult
    {
        while ($this->attempts < $this->maxRetries) {
            try {
                $this->transport->connect();
                return new ConnectionResult(retried: $this->attempts > 0, attempts: $this->attempts + 1);
            } catch (ConnectionClosedException $e) {
                $this->attempts++;
                usleep($this->backoffMs * 1000 * $this->attempts); // Exponential backoff
            }
        }

        throw new ConnectionClosedException('Max retries exceeded');
    }
}

readonly class ConnectionResult
{
    public function __construct(
        public readonly bool $retried,
        public readonly int $attempts,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Connection/AutoReconnectTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Connection/AutoReconnect.php tests/Unit/Connection/
git commit -m "feat: add AutoReconnect with exponential backoff"
```

---

## Chunk 5: Address Helper

### Task 5.1: AddressHelper Implementation

**Files:**
- Create: `src/AMQP10/Address/AddressHelper.php`

- [ ] **Step 1: Write test for address construction**

```php
// tests/Unit/Address/AddressHelperTest.php
namespace AMQP10\Tests\Address;

use AMQP10\Address\AddressHelper;
use PHPUnit\Framework\TestCase;

class AddressHelperTest extends TestCase
{
    public function test_exchange_address_with_routing_key(): void
    {
        $address = AddressHelper::exchangeAddress('my-exchange', 'my-key');
        $this->assertSame('/exchanges/my-exchange/my-key', $address);
    }

    public function test_queue_address(): void
    {
        $address = AddressHelper::queueAddress('my-queue');
        $this->assertSame('/queues/my-queue', $address);
    }

    public function test_stream_address(): void
    {
        $address = AddressHelper::streamAddress('my-stream');
        $this->assertSame('/streams/my-stream', $address);
    }

    public function test_percent_encoding(): void
    {
        $address = AddressHelper::exchangeAddress('my/exchange', 'my/key');
        $this->assertSame('/exchanges/my%2Fexchange/my%2Fkey', $address);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Address/AddressHelperTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement AddressHelper**

```php
// src/AMQP10/Address/AddressHelper.php
namespace AMQP10\Address;

class AddressHelper
{
    public static function exchangeAddress(string $exchangeName, string $routingKey = ''): string
    {
        $encodedExchange = self::encodePathSegment($exchangeName);
        $encodedKey = self::encodePathSegment($routingKey);

        if ($routingKey === '') {
            return "/exchanges/$encodedExchange";
        }

        return "/exchanges/$encodedExchange/$encodedKey";
    }

    public static function queueAddress(string $queueName): string
    {
        $encoded = self::encodePathSegment($queueName);
        return "/queues/$encoded";
    }

    public static function streamAddress(string $streamName): string
    {
        $encoded = self::encodePathSegment($streamName);
        return "/streams/$encoded";
    }

    private static function encodePathSegment(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $encoded = [];
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            if (self::isUnreserved($char)) {
                $encoded[] = $char;
            } else {
                $encoded[] = '%' . strtoupper(sprintf('%02X', ord($char)));
            }
        }

        return implode('', $encoded);
    }

    private static function isUnreserved(string $char): bool
    {
        return ctype_alnum($char) || in_array($char, ['-', '.', '_', '~']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Address/AddressHelperTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Address/ tests/Unit/Address/
git commit -m "feat: add AddressHelper for AMQP 1.0 address construction"
```

---

## Chunk 6: Messaging Layer

### Task 6.1: Message

**Files:**
- Create: `src/AMQP10/Messaging/Message.php`

- [ ] **Step 1: Write test for message creation**

```php
// tests/Unit/Messaging/MessageTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_message_creation(): void
    {
        $message = new Message('hello world', properties: ['content-type' => 'text/plain']);

        $this->assertSame('hello world', $message->body());
        $this->assertSame('text/plain', $message->property('content-type'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/MessageTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Message class**

```php
// src/AMQP10/Messaging/Message.php
namespace AMQP10\Messaging;

readonly class Message
{
    private array $properties = [];
    private array $applicationProperties = [];
    private array $annotations = [];

    public function __construct(
        private readonly string $body,
        array $properties = [],
        array $applicationProperties = [],
        array $annotations = [],
    ) {
        $this->properties = $properties;
        $this->applicationProperties = $applicationProperties;
        $this->annotations = $annotations;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function property(string $key): mixed
    {
        return $this->properties[$key] ?? null;
    }

    public function applicationProperty(string $key): mixed
    {
        return $this->applicationProperties[$key] ?? null;
    }

    public function annotation(string $key): mixed
    {
        return $this->annotations[$key] ?? null;
    }

    public function encode(): string
    {
        // Encode message using AMQP 1.0 format
        // Header + Properties + Application Properties + Annotations + Body
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/MessageTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add Message class with AMQP 1.0 properties"
```

### Task 6.2: Outcome and DeliveryContext

**Files:**
- Create: `src/AMQP10/Messaging/Outcome.php`
- Create: `src/AMQP10/Messaging/DeliveryContext.php`

- [ ] **Step 1: Write tests for outcome**

```php
// tests/Unit/Messaging/OutcomeTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Outcome;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function test_accepted_outcome(): void
    {
        $outcome = Outcome::accepted();
        $this->assertTrue($outcome->isAccepted());
        $this->assertFalse($outcome->isRejected());
        $this->assertFalse($outcome->isReleased());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/OutcomeTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Outcome class**

```php
// src/AMQP10/Messaging/Outcome.php
namespace AMQP10\Messaging;

readonly class Outcome
{
    private function __construct(
        public readonly OutcomeState $state,
        public readonly ?string $error = null,
    ) {}

    public static function accepted(): self
    {
        return new self(OutcomeState::ACCEPTED);
    }

    public static function rejected(): self
    {
        return new self(OutcomeState::REJECTED);
    }

    public static function released(): self
    {
        return new self(OutcomeState::RELEASED);
    }

    public static function modified(): self
    {
        return new self(OutcomeState::MODIFIED);
    }

    public function isAccepted(): bool
    {
        return $this->state === OutcomeState::ACCEPTED;
    }
}

enum OutcomeState: int
{
    case ACCEPTED = 0;
    case REJECTED = 1;
    case RELEASED = 2;
    case MODIFIED = 3;
}
```

- [ ] **Step 4: Implement DeliveryContext**

```php
// src/AMQP10/Messaging/DeliveryContext.php
namespace AMQP10\Messaging;

readonly class DeliveryContext
{
    public function __construct(
        private readonly mixed $deliveryTag,
        private readonly \Closure $ackCallback,
        private readonly \Closure $nackCallback,
    ) {}

    public function accept(): void
    {
        ($this->ackCallback)($this->deliveryTag, accept: true);
    }

    public function reject(bool $requeue = true): void
    {
        ($this->nackCallback)($this->deliveryTag, requeue: false);
    }

    public function requeue(): void
    {
        ($this->nackCallback)($this->deliveryTag, requeue: true);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add Outcome and DeliveryContext for message settlement"
```

### Task 6.3: Publisher

**Files:**
- Create: `src/AMQP10/Messaging/Publisher.php`
- Create: `src/AMQP10/Messaging/PublisherBuilder.php`

- [ ] **Step 1: Write test for publisher**

```php
// tests/Unit/Messaging/PublisherTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Publisher;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class PublisherTest extends TestCase
{
    public function test_publish_message(): void
    {
        $transport = new TransportMock();
        $publisher = new Publisher($transport, '/exchanges/test/key');

        $message = new Message('test');
        $outcome = $publisher->send($message);

        $this->assertTrue($outcome->isAccepted());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/PublisherTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Publisher class**

```php
// src/AMQP10/Messaging/Publisher.php
namespace AMQP10\Messaging;

use AMQP10\Exception\PublishException;
use AMQP10\Protocol\Encoder;
use AMQP10\Transport\TransportInterface;

class Publisher
{
    private int $ttl = 0;
    private int $priority = 4;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $address,
    ) {}

    public function ttl(int $millis): self
    {
        $this->ttl = $millis;
        return $this;
    }

    public function priority(int $value): self
    {
        $this->priority = $value;
        return $this;
    }

    public function send(Message $message): Outcome
    {
        $encoded = $message->encode();
        $frame = Encoder::createTransferFrame($this->address, $encoded, $this->ttl, $this->priority);

        $this->transport->sendFrame($frame);

        // Wait for outcome
        return $this->waitForOutcome();
    }

    private function waitForOutcome(): Outcome
    {
        // Implement outcome handling (Accepted/Rejected/Released)
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/PublisherTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add Publisher with fluent builder pattern"
```

### Task 6.4: Consumer

**Files:**
- Create: `src/AMQP10/Messaging/Consumer.php`
- Create: `src/AMQP10/Messaging/ConsumerBuilder.php`

- [ ] **Step 1: Write test for consumer**

```php
// tests/Unit/Messaging/ConsumerTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Consumer;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    public function test_consume_message(): void
    {
        $transport = new TransportMock();
        $consumer = new Consumer($transport, '/queues/test');

        $messages = [];
        $consumer->handle(function ($msg, $ctx) use (&$messages) {
            $messages[] = $msg;
            $ctx->accept();
        });

        $transport->simulateDelivery($messages);
        $this->assertCount(1, $messages);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Consumer class**

```php
// src/AMQP10/Messaging/Consumer.php
namespace AMQP10\Messaging;

use AMQP10\Exception\ConsumerException;
use AMQP10\Exception\MessageTimeoutException;
use AMQP10\Transport\TransportInterface;

class Consumer
{
    private ?\Closure $handler = null;
    private ?\Closure $errorHandler = null;
    private int $prefetch = 10;
    private int $credit = 10;
    private ?string $filterSql = null;
    private ?array $filterValues = null;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $address,
    ) {}

    public function handle(\Closure $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function prefetch(int $count): self
    {
        $this->prefetch = $count;
        return $this;
    }

    public function credit(int $value): self
    {
        $this->credit = $value;
        return $this;
    }

    public function filterSql(string $sql): self
    {
        $this->filterSql = $sql;
        return $this;
    }

    public function filterValues(string ...$values): self
    {
        $this->filterValues = $values;
        return $this;
    }

    public function onError(\Closure $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function run(): void
    {
        // Attach consumer link
        $this->attachLink();

        // Start receiving loop
        while ($this->transport->isConnected()) {
            $frame = $this->transport->receiveFrame();
            if ($frame !== null) {
                $this->processFrame($frame);
            }
        }
    }

    private function attachLink(): void
    {
        // Implement AMQP 1.0 attach flow with source address
    }

    private function processFrame(string $frame): void
    {
        // Decode frame and pass to handler if it's a message
        $message = $this->decodeMessage($frame);

        if ($message !== null && $this->handler !== null) {
            $ctx = new DeliveryContext($message->deliveryTag, $this->ack(...), $this->nack(...));

            try {
                ($this->handler)($message, $ctx);
            } catch (\Throwable $e) {
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($e);
                }
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/ConsumerTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add Consumer with filtering and flow control"
```

---

## Chunk 7: Management API

### Task 7.1: Management Specifications

**Files:**
- Create: `src/AMQP10/Management/ExchangeSpecification.php`
- Create: `src/AMQP10/Management/QueueSpecification.php`
- Create: `src/AMQP10/Management/BindingSpecification.php`

- [ ] **Step 1: Write test for specifications**

```php
// tests/Unit/Management/SpecificationTest.php
namespace AMQP10\Tests\Management;

use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\QueueSpecification;
use PHPUnit\Framework\TestCase;

class SpecificationTest extends TestCase
{
    public function test_exchange_spec(): void
    {
        $spec = new ExchangeSpecification('test', ExchangeType::DIRECT);
        $this->assertSame('test', $spec->name);
        $this->assertSame(ExchangeType::DIRECT, $spec->type);
    }

    public function test_queue_spec(): void
    {
        $spec = new QueueSpecification('test', QueueType::QUORUM, durable: true);
        $this->assertSame('test', $spec->name);
        $this->assertSame(QueueType::QUORUM, $spec->type);
        $this->assertTrue($spec->durable);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Management/SpecificationTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement specifications**

```php
// src/AMQP10/Management/ExchangeSpecification.php
namespace AMQP10\Management;

readonly class ExchangeSpecification
{
    public function __construct(
        public readonly string $name,
        public readonly ExchangeType $type = ExchangeType::DIRECT,
        public readonly bool $durable = false,
        public readonly ?bool $autoDelete = null,
    ) {}
}

enum ExchangeType: string
{
    case DIRECT = 'direct';
    case FANOUT = 'fanout';
    case TOPIC = 'topic';
    case HEADERS = 'headers';
}

// src/AMQP10/Management/QueueSpecification.php
namespace AMQP10\Management;

readonly class QueueSpecification
{
    public function __construct(
        public readonly string $name,
        public readonly QueueType $type = QueueType::CLASSIC,
        public readonly bool $durable = false,
        public readonly ?bool $exclusive = null,
    ) {}
}

enum QueueType: string
{
    case CLASSIC = 'classic';
    case QUORUM = 'quorum';
    case STREAM = 'stream';
}

// src/AMQP10/Management/BindingSpecification.php
namespace AMQP10\Management;

readonly class BindingSpecification
{
    public function __construct(
        public readonly string $sourceExchange,
        public readonly string $destinationQueue,
        public readonly ?string $bindingKey = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Management/SpecificationTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Management/ tests/Unit/Management/
git commit -m "feat: add management specifications for exchanges, queues, bindings"
```

### Task 7.2: Management Interface

**Files:**
- Create: `src/AMQP10/Management/ManagementInterface.php`

- [ ] **Step 1: Write test for management operations**

```php
// tests/Unit/Management/ManagementInterfaceTest.php
namespace AMQP10\Tests\Management;

use AMQP10\Management\ManagementInterface;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ManagementInterfaceTest extends TestCase
{
    public function test_declare_exchange(): void
    {
        $transport = new TransportMock();
        $management = new ManagementInterface($transport);

        $spec = new ExchangeSpecification('test', ExchangeType::DIRECT);
        $management->declareExchange($spec);

        $this->assertTrue($transport->sentFrame('declare'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Management/ManagementInterfaceTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement ManagementInterface**

```php
// src/AMQP10/Management/ManagementInterface.php
namespace AMQP10\Management;

use AMQP10\Exception\ExchangeNotFoundException;
use AMQP10\Exception\QueueNotFoundException;
use AMQP10\Exception\BindingException;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\BindingSpecification;
use AMQP10\Protocol\Encoder;
use AMQP10\Transport\TransportInterface;

class ManagementInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
    ) {}

    public function declareExchange(ExchangeSpecification $spec): void
    {
        $frame = Encoder::createAttachFrame(
            source: null,
            target: "/exchanges/{$spec->name}",
            properties: [
                'type' => $spec->type,
                'durable' => $spec->durable,
            ],
        );

        $this->transport->sendFrame($frame);
    }

    public function declareQueue(QueueSpecification $spec): void
    {
        $frame = Encoder::createAttachFrame(
            source: null,
            target: "/queues/{$spec->name}",
            properties: [
                'type' => $spec->type,
                'durable' => $spec->durable,
            ],
        );

        $this->transport->sendFrame($frame);
    }

    public function bind(BindingSpecification $spec): string
    {
        $bindName = uniqid();

        $frame = Encoder::createAttachFrame(
            source: "/exchanges/{$spec->sourceExchange}",
            target: "/queues/{$spec->destinationQueue}",
            properties: [
                'binding-key' => $spec->bindingKey,
            ],
        );

        $this->transport->sendFrame($frame);
        return $bindName;
    }

    public function deleteQueue(string $name): void
    {
        $frame = Encoder::createDetachFrame("/queues/$name");
        $this->transport->sendFrame($frame);
    }

    public function deleteExchange(string $name): void
    {
        $frame = Encoder::createDetachFrame("/exchanges/$name");
        $this->transport->sendFrame($frame);
    }

    public function unbind(string $bindName): void
    {
        $frame = Encoder::createDetachFrame($bindName);
        $this->transport->sendFrame($frame);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Management/ManagementInterfaceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Management/ tests/Unit/Management/
git commit -m "feat: add ManagementInterface for topology operations"
```

---

## Chunk 8: Client API

### Task 8.1: Client Class

**Files:**
- Create: `src/AMQP10/Client/Client.php`

- [ ] **Step 1: Write test for client connection**

```php
// tests/Unit/Client/ClientTest.php
namespace AMQP10\Tests\Client;

use AMQP10\Client\Client;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function test_client_connect(): void
    {
        $client = Client::connect('amqp://guest:guest@localhost:5672/');
        $this->assertFalse($client->isConnected());

        $client->open();
        $this->assertTrue($client->isConnected());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Client/ClientTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement Client class**

```php
// src/AMQP10/Client/Client.php
namespace AMQP10\Client;

use AMQP10\Client\Config;
use AMQP10\Connection\Connection;
use AMQP10\Connection\AutoReconnect;
use AMQP10\Management\ManagementInterface;
use AMQP10\Messaging\Publisher;
use AMQP10\Messaging\Consumer;
use AMQP10\Transport\TransportInterface;
use AMQP10\Transport\BlockingAdapter;
use AMQP10\Transport\ReactAdapter;
use AMQP10\Transport\SwooleAdapter;
use AMQP10\Transport\AmpAdapter;

class Client
{
    private ?Config $config = null;
    private ?Connection $connection = null;

    public static function connect(string $uri, ?Config $config = null): static
    {
        $client = new static($uri, $config);
        $client->open();
        return $client;
    }

    public static function connectWithAdapter(string $uri, TransportInterface $adapter): static
    {
        $client = new static($uri, new Config(), $adapter);
        $client->open();
        return $client;
    }

    private function __construct(
        private readonly string $uri,
        private ?Config $config = null,
        private ?TransportInterface $adapter = null,
    ) {
        $this->config = $config ?? new Config();
    }

    public function withAutoReconnect(int $maxRetries = 5, int $backoffMs = 1000): static
    {
        $this->config = new Config(
            autoReconnect: true,
            maxRetries: $maxRetries,
            backoffMs: $backoffMs,
        );
        return $this;
    }

    public function withSasl(Sasl $sasl): static
    {
        $this->config = new Config(sasl: $sasl);
        return $this;
    }

    private function open(): void
    {
        $adapter = $this->adapter ?? $this->createDefaultAdapter();
        $connection = new Connection($adapter, $this->uri, $this->config);

        if ($this->config->autoReconnect) {
            $autoReconnect = new AutoReconnect($adapter, $this->config->maxRetries, $this->config->backoffMs);
            $autoReconnect->connectWithRetry();
            $connection = new Connection($adapter, $this->uri, $this->config);
        } else {
            $connection->open();
        }

        $this->connection = $connection;
    }

    public function close(): void
    {
        $this->connection?->close();
    }

    public function management(): ManagementInterface
    {
        return new ManagementInterface($this->connection);
    }

    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder($this->connection, $address);
    }

    public function consume(string $address): ConsumerBuilder
    {
        return new ConsumerBuilder($this->connection, $address);
    }

    public function isConnected(): bool
    {
        return $this->connection?->isOpen() ?? false;
    }

    private function createDefaultAdapter(): TransportInterface
    {
        return new BlockingAdapter(); // Default to blocking
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Client/ClientTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Client/ tests/Unit/Client/
git commit -m "feat: add Client with fluent API and auto-reconnect"
```

### Task 8.2: Builder Classes

**Files:**
- Create: `src/AMQP10/Messaging/PublisherBuilder.php`
- Create: `src/AMQP10/Messaging/ConsumerBuilder.php`

- [ ] **Step 1: Write tests for builders**

```php
// tests/Unit/Messaging/PublisherBuilderTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Messaging\Message;
use PHPUnit\Framework\TestCase;

class PublisherBuilderTest extends TestCase
{
    public function test_builder_fluent_api(): void
    {
        $connection = createMockConnection();
        $builder = new PublisherBuilder($connection, '/exchanges/test/key');

        $publisher = $builder->ttl(60000)->priority(5)->build();

        $this->assertInstanceOf(Publisher::class, $publisher);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/
```

Expected: FAIL

- [ ] **Step 3: Implement PublisherBuilder**

```php
// src/AMQP10/Messaging/PublisherBuilder.php
namespace AMQP10\Messaging;

readonly class PublisherBuilder
{
    private int $ttl = 0;
    private int $priority = 4;

    public function __construct(
        private readonly mixed $connection,
        private readonly string $address,
    ) {}

    public function ttl(int $millis): self
    {
        $this->ttl = $millis;
        return $this;
    }

    public function priority(int $value): self
    {
        $this->priority = $value;
        return $this;
    }

    public function send(Message $message): Outcome
    {
        $publisher = new Publisher($this->connection, $this->address);
        return $publisher->ttl($this->ttl)->priority($this->priority)->send($message);
    }
}
```

- [ ] **Step 4: Implement ConsumerBuilder**

```php
// src/AMQP10/Messaging/ConsumerBuilder.php
namespace AMQP10\Messaging;

readonly class ConsumerBuilder
{
    private ?\Closure $handler = null;
    private ?\Closure $errorHandler = null;
    private int $prefetch = 10;
    private int $credit = 10;
    private ?string $filterSql = null;
    private ?array $filterValues = null;

    public function __construct(
        private readonly mixed $connection,
        private readonly string $address,
    ) {}

    public function prefetch(int $count): self
    {
        $this->prefetch = $count;
        return $this;
    }

    public function credit(int $value): self
    {
        $this->credit = $value;
        return $this;
    }

    public function filterSql(string $sql): self
    {
        $this->filterSql = $sql;
        return $this;
    }

    public function filterValues(string ...$values): self
    {
        $this->filterValues = $values;
        return $this;
    }

    public function handle(\Closure $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function onError(\Closure $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function run(): void
    {
        $consumer = new Consumer($this->connection, $this->address);
        $consumer->handle($this->handler ?? fn() => null)
            ->prefetch($this->prefetch)
            ->credit($this->credit)
            ->filterSql($this->filterSql ?? '')
            ->filterValues(...($this->filterValues ?? []))
            ->onError($this->errorHandler ?? fn() => null);
        $consumer->run();
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add fluent builders for Publisher and Consumer"
```

---

## Chunk 9: Stream Support

### Task 9.1: Stream Consumer Enhancements

**Files:**
- Modify: `src/AMQP10/Messaging/Consumer.php`

- [ ] **Step 1: Write test for stream offset**

```php
// tests/Unit/Messaging/StreamConsumerTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Consumer;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class StreamConsumerTest extends TestCase
{
    public function test_stream_offset(): void
    {
        $transport = new TransportMock();
        $consumer = new Consumer($transport, '/streams/test');

        $consumer->offset(Offset::FIRST);
        $this->assertTrue($consumer->isStream());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/StreamConsumerTest.php
```

Expected: FAIL

- [ ] **Step 3: Add stream-specific methods to Consumer**

```php
// Add to src/AMQP10/Messaging/Consumer.php

enum Offset
{
    case FIRST = 'first';
    case LAST = 'last';
    case NEXT = 'next';
    case OFFSET = 'offset';
}

class Consumer
{
    // ... existing properties ...

    private ?Offset $offset = null;
    private bool $stream = false;

    public function offset(Offset $value, int $number = 0): self
    {
        $this->offset = $value === Offset::OFFSET ? Offset::OFFSET : $value;
        return $this;
    }

    public function isStream(): bool
    {
        return $this->stream;
    }

    private function attachLink(): void
    {
        // Detect if it's a stream address
        $this->stream = str_starts_with($this->address, '/streams/');

        // Add stream filter to attach frame
        $filters = [];
        if ($this->filterValues !== null) {
            $filters['rabbitmq:stream-filter'] = $this->filterValues;
        }
        if ($this->filterSql !== null) {
            $filters['amqp:sql-filter'] = $this->filterSql;
        }

        // Include offset in attach frame
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/StreamConsumerTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add stream support with offset and filtering"
```

### Task 9.2: Stream Filter Support

**Files:**
- Create: `src/AMQP10/Messaging/StreamFilter.php`

- [ ] **Step 1: Write test for stream filter annotation**

```php
// tests/Unit/Messaging/StreamFilterTest.php
namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Message;
use PHPUnit\Framework\TestCase;

class StreamFilterTest extends TestCase
{
    public function test_stream_filter_value(): void
    {
        $message = new Message('test', annotations: ['x-stream-filter-value' => 'orders']);
        $encoded = $message->encode();

        $this->assertStringContainsString('orders', $encoded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/StreamFilterTest.php
```

Expected: FAIL

- [ ] **Step 3: Add stream filter support to Message**

```php
// Modify src/AMQP10/Messaging/Message.php

class Message
{
    // ... existing code ...

    public function withStreamFilter(string $value): self
    {
        $this->annotations['x-stream-filter-value'] = $value;
        return $this;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Unit/Messaging/StreamFilterTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Messaging/ tests/Unit/Messaging/
git commit -m "feat: add stream filter annotation support"
```

---

## Chunk 10: Testing Mocks

### Task 10.1: TransportMock

**Files:**
- Create: `tests/Mocks/TransportMock.php`
- Create: `tests/Mocks/FrameBuilder.php`
- Create: `tests/Mocks/BrokerSimulator.php`

- [ ] **Step 1: Write test for TransportMock**

```php
// tests/Mocks/TransportMockTest.php
namespace AMQP10\Tests\Mocks;

use PHPUnit\Framework\TestCase;

class TransportMockTest extends TestCase
{
    public function test_mock_sends_frames(): void
    {
        $mock = new TransportMock();
        $mock->sendFrame("test frame");

        $this->assertSame(['test frame'], $mock->getSentFrames());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Mocks/TransportMockTest.php
```

Expected: FAIL

- [ ] **Step 3: Implement TransportMock**

```php
// tests/Mocks/TransportMock.php
namespace AMQP10\Tests\Mocks;

class TransportMock
{
    private array $sentFrames = [];
    private array $queuedFrames = [];

    public function __construct(
        private readonly bool $shouldFail = false,
    private readonly bool $shouldTimeout = false,
    ) {}

    public function connect(string $uri): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Mock connection failed');
        }
    }

    public function disconnect(): void
    {
        // Mock disconnect
    }

    public function sendFrame(string $frame): void
    {
        $this->sentFrames[] = $frame;
        $this->queuedFrames[] = $frame;
    }

    public function receiveFrame(): ?string
    {
        if ($this->shouldTimeout) {
            usleep(100000); // 100ms
            return null;
        }

        return array_shift($this->queuedFrames) ?? null;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function getSentFrames(): array
    {
        return $this->sentFrames;
    }

    public function queueFrame(string $frame): void
    {
        $this->queuedFrames[] = $frame;
    }
}
```

- [ ] **Step 4: Implement FrameBuilder**

```php
// tests/Mocks/FrameBuilder.php
namespace AMQP10\Tests\Mocks;

class FrameBuilder
{
    public static function createOpenFrame(): string
    {
        // Return valid AMQP 1.0 OPEN frame
    }

    public static function createAttachFrame(array $options): string
    {
        // Return valid AMQP 1.0 ATTACH frame with source/target
    }

    public static function createTransferFrame(string $address, string $message, int $ttl, int $priority): string
    {
        // Return valid AMQP 1.0 TRANSFER frame
    }

    public static function createDispositionFrame(string $outcome): string
    {
        // Return valid AMQP 1.0 DISPOSITION frame (Accepted/Rejected/Released)
    }
}
```

- [ ] **Step 5: Implement BrokerSimulator**

```php
// tests/Mocks/BrokerSimulator.php
namespace AMQP10\Tests\Mocks;

class BrokerSimulator
{
    public static function simulateAccepted(): string
    {
        return FrameBuilder::createDispositionFrame('accepted');
    }

    public static function simulateRejected(): string
    {
        return FrameBuilder::createDispositionFrame('rejected');
    }

    public static function simulateReleased(): string
    {
        return FrameBuilder::createDispositionFrame('released');
    }
}
```

- [ ] **Step 6: Run test to verify they pass**

```bash
./vendor/bin/phpunit tests/Mocks/TransportMockTest.php
```

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add tests/mocks/
git commit -m "test: add testing mocks for Transport, FrameBuilder, BrokerSimulator"
```

---

## Chunk 11: Documentation

### Task 11.1: README

**Files:**
- Create: `README.md`

- [ ] **Step 1: Create README structure**

```markdown
# PHP AMQP 1.0 Client

Modern PHP 8.1+ client library for AMQP 1.0 messaging with RabbitMQ 4.0+.

## Installation

```bash
composer require php-amqp10/client
```

## Quick Start

```php
use AMQP10\Client\Client;
use AMQP10\Address\AddressHelper;
use AMQP10\Messaging\Message;

$client = Client::connect('amqp://guest:guest@localhost:5672/');

// Publish message
$address = AddressHelper::exchangeAddress('my-exchange', 'my-key');
$client->publish($address)
    ->send(new Message('hello world'));

// Consume messages
$address = AddressHelper::queueAddress('my-queue');
$client->consume($address)
    ->handle(function ($msg, $ctx) {
        echo $msg->body() . "\n";
        $ctx->accept();
    })
    ->run();
```

## Features

- AMQP 1.0 protocol support
- Fluent API for easy development
- Runtime adapters: Blocking, ReactPHP, Swoole, AMPHP
- Auto-reconnection with exponential backoff
- Stream support with Bloom filters and SQL filter expressions
- Full RabbitMQ topology management (exchanges, queues, bindings)

## Documentation

Full documentation at [docs/](docs/)

## License

Apache 2.0
```

- [ ] **Step 2: Verify README renders**

```bash
# No test needed, just check markdown rendering
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add comprehensive README"
```

### Task 11.2: Examples

**Files:**
- Create: `examples/quickstart.php`
- Create: `examples/publish.php`
- Create: `examples/consume.php`
- Create: `examples/streams.php`
- Create: `examples/management.php`

- [ ] **Step 1: Create quickstart example**

```php
// examples/quickstart.php
<?php

require __DIR__ . '/vendor/autoload.php';

use AMQP10\Client\Client;
use AMQP10\Address\AddressHelper;
use AMQP10\Messaging\Message;

$client = Client::connect('amqp://guest:guest@localhost:5672/');

// Simple publish
$address = AddressHelper::exchangeAddress('test', 'hello');
$client->publish($address)
    ->send(new Message('Hello AMQP 1.0!'));

// Simple consume
$address = AddressHelper::queueAddress('test');
$client->consume($address)
    ->handle(function ($msg, $ctx) {
        echo "Received: {$msg->body()}\n";
        $ctx->accept();
    })
    ->run();
```

- [ ] **Step 2: Create publish example**

```php
// examples/publish.php
<?php

require __DIR__ . '/vendor/autoload.php';

use AMQP10\Client\Client;
use AMQP10\Address\AddressHelper;
use AMQP10\Messaging\Message;

$client = Client::connect('amqp://guest:guest@localhost:5672/');
$management = $client->management();

// Declare exchange
$management->declareExchange(
    new AMQP10\Management\ExchangeSpecification('orders', AMQP10\Management\ExchangeType::DIRECT)
);

// Publish messages
$address = AddressHelper::exchangeAddress('orders', 'created');

for ($i = 0; $i < 10; $i++) {
    $outcome = $client->publish($address)
        ->ttl(60000)
        ->priority(5)
        ->send(new Message(json_encode(['order_id' => $i, 'item' => 'product'])));

    echo "Published message $i: {$outcome->state->name}\n";
}
```

- [ ] **Step 3: Create consume example**

```php
// examples/consume.php
<?php

require __DIR__ . '/vendor/autoload.php';

use AMQP10\Client\Client;
use AMQP10\Address\AddressHelper;

$client = Client::connect('amqp://guest:guest@localhost:5672/');

$address = AddressHelper::queueAddress('orders');

$client->consume($address)
    ->prefetch(10)
    ->handle(function ($msg, $ctx) {
        $order = json_decode($msg->body(), true);
        echo "Processing order {$order['order_id']}\n";

        try {
            processOrder($order);
            $ctx->accept();
        } catch (\Exception $e) {
            echo "Error processing order: {$e->getMessage()}\n";
            $ctx->reject(requeue: false); // Don't requeue on error
        }
    })
    ->onError(function ($e) {
        echo "Consumer error: {$e->getMessage()}\n";
    })
    ->run();
```

- [ ] **Step 4: Create stream example**

```php
// examples/streams.php
<?php

require __DIR__ . '/vendor/autoload.php';

use AMQP10\Client\Client;
use AMQP10\Address\AddressHelper;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

$client = Client::connect('amqp://guest:guest@localhost:5672/');

$address = AddressHelper::streamAddress('events');

// Consume from stream with filtering
$client->consume($address)
    ->offset(Offset::LAST) // Start from newest
    ->filterSql("priority >= 5 AND region = 'EMEA'") // SQL filter
    ->credit(100) // More aggressive credit for streams
    ->handle(function ($msg, $ctx) {
        $event = json_decode($msg->body(), true);
        echo "High priority EMEA event: {$event['type']}\n";
        $ctx->accept();
    })
    ->run();
```

- [ ] **Step 5: Create management example**

```php
// examples/management.php
<?php

require __DIR__ . '/vendor/autoload.php';

use AMQP10\Client\Client;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Management\BindingSpecification;
use AMQP10\Address\AddressHelper;

$client = Client::connect('amqp://guest:guest@localhost:5672/');
$management = $client->management();

// Declare exchange
$management->declareExchange(
    new ExchangeSpecification('orders', ExchangeType::TOPIC)
);

// Declare queue
$management->declareQueue(
    new QueueSpecification('orders-queue', QueueType::QUORUM, durable: true)
);

// Bind them
$bindName = $management->bind(
    new BindingSpecification('orders', 'orders-queue', 'order.created')
);

echo "Topology declared\n";
echo "Exchange: orders\n";
echo "Queue: orders-queue\n";
echo "Binding: $bindName\n";

// Cleanup
$management->deleteQueue('orders-queue');
$management->deleteExchange('orders');
```

- [ ] **Step 6: Commit**

```bash
git add examples/
git commit -m "docs: add example code for common use cases"
```

### Task 11.3: API Documentation

**Files:**
- Create: `docs/api.md`

- [ ] **Step 1: Create API documentation**

```markdown
# API Documentation

## Client

```php
namespace AMQP10\Client;

class Client
{
    /**
     * Connect to AMQP broker
     *
     * @param string $uri Connection URI (amqp://user:pass@host:port/)
     * @param Config|null $config Configuration options
     * @return Client Connected client instance
     */
    public static function connect(string $uri, ?Config $config = null): static;

    /**
     * Connect with custom transport adapter
     *
     * @param string $uri Connection URI
     * @param TransportInterface $adapter Custom transport adapter
     * @return Client Connected client instance
     */
    public static function connectWithAdapter(string $uri, TransportInterface $adapter): static;

    /**
     * Enable auto-reconnect
     *
     * @param int $maxRetries Maximum reconnection attempts
     * @param int $backoffMs Exponential backoff base in milliseconds
     * @return Client
     */
    public function withAutoReconnect(int $maxRetries = 5, int $backoffMs = 1000): static;

    /**
     * Get management interface for topology operations
     *
     * @return ManagementInterface
     */
    public function management(): ManagementInterface;

    /**
     * Create publisher for given address
     *
     * @param string $address AMQP 1.0 address
     * @return PublisherBuilder
     */
    public function publish(string $address): PublisherBuilder;

    /**
     * Create consumer for given address
     *
     * @param string $address AMQP 1.0 address
     * @return ConsumerBuilder
     */
    public function consume(string $address): ConsumerBuilder;

    /**
     * Close connection
     */
    public function close(): void;
}
```

## Management

## Messaging

## Address

... document all public classes and methods ...
```

- [ ] **Step 2: Verify API docs completeness**

```bash
# Manual review for completeness
```

- [ ] **Step 3: Commit**

```bash
git add docs/api.md
git commit -m "docs: add API documentation"
```

### Task 11.4: Docker Setup

**Files:**
- Create: `Dockerfile`
- Create: `docker-compose.yml`

- [ ] **Step 1: Create Dockerfile**

```dockerfile
FROM php:8.1-cli

RUN apt-get update && apt-get install -y git unzip

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist

COPY . .

CMD ["php", "-a"]
```

- [ ] **Step 2: Create docker-compose**

```yaml
version: '3.8'

services:
  rabbitmq:
    image: rabbitmq:4-management
    ports:
      - "5672:5672"
      - "15672:15672"
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest

  app:
    build: .
    volumes:
      - .:/app
    depends_on:
      - rabbitmq
```

- [ ] **Step 3: Create docker-test script**

```bash
#!/bin/bash

# docker-test.sh

docker-compose up -d rabbitmq
docker-compose run --rm app composer test
docker-compose down
```

- [ ] **Step 4: Make script executable**

```bash
chmod +x docker-test.sh
```

- [ ] **Step 5: Commit**

```bash
git add Dockerfile docker-compose.yml docker-test.sh
git commit -m "docs: add Docker setup for development and testing"
```

---

## Final Steps

### Task 12.1: Run All Tests

- [ ] **Step 1: Run complete test suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass

- [ ] **Step 2: Generate code coverage report**

```bash
./vendor/bin/phpunit --coverage-html
```

- [ ] **Step 3: Review coverage**

Check that all critical paths are covered, aim for >80% coverage

- [ ] **Step 4: Fix any coverage gaps**

Add tests for uncovered code if needed

- [ ] **Step 5: Commit**

```bash
git add ./
git commit -m "test: achieve 80%+ code coverage"
```

### Task 12.2: Static Analysis

- [ ] **Step 1: Run PHPStan**

```bash
./vendor/bin/phpstan analyse src/
```

- [ ] **Step 2: Fix any issues**

Address all PHPStan warnings/errors

- [ ] **Step 3: Commit**

```bash
git add ./
git commit -m "fix: resolve PHPStan issues"
```

### Task 12.3: Prepare Release

- [ ] **Step 1: Update CHANGELOG**

```markdown
# Changelog

## [Unreleased]

### Added
- Initial AMQP 1.0 PHP client library
- Fluent API for easy development
- Support for Blocking, ReactPHP, Swoole, AMPHP runtimes
- Auto-reconnection with exponential backoff
- Stream support with Bloom filters and SQL filter expressions
- Full RabbitMQ topology management
- Comprehensive unit test coverage
```

- [ ] **Step 2: Create git tag**

```bash
git tag -a v0.1.0 -m "Initial release"
```

- [ ] **Step 3: Commit and push**

```bash
git add CHANGELOG.md
git commit -m "chore: prepare v0.1.0 release"
git push origin main
git push origin v0.1.0
```

---

## Completion Checklist

- [ ] All chunks completed
- [ ] All tests passing (>80% coverage)
- [ ] No PHPStan errors
- [ ] Documentation complete
- [ ] Examples working
- [ ] Docker setup verified
- [ ] Release tagged

---

**Plan complete!** Start implementation using `superpowers:subagent-driven-development` or `superpowers:executing-plans`.

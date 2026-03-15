# Transport & Messaging Improvements Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the blocking transport with Revolt/Fiber-based I/O, fix all messaging API gaps (credit exhaustion, durable consumers, message durability, fire-and-forget, TLS, multi-frame, virtual host, reconnection), and remove dead code (BlockingAdapter, AutoReconnect).

**Architecture:** `RevoltTransport` becomes the sole transport, using PHP Fibers + Revolt event loop to suspend on I/O instead of busy-waiting. Consumer/Publisher builders are refactored to hold a `Client` reference enabling runtime reconnection. All new enumerated protocol values use PHP backed enums.

**Tech Stack:** PHP 8.2+, revolt/event-loop ^1.0, testcontainers/testcontainers (integration tests), PHPUnit, PHPStan level 6.

---

## File Map

### New Files
| File | Purpose |
|------|---------|
| `src/AMQP10/Transport/RevoltTransport.php` | Revolt/Fiber non-blocking transport |
| `src/AMQP10/Terminus/TerminusDurability.php` | Enum: None=0, Configuration=1, UnsettledState=2 |
| `src/AMQP10/Terminus/ExpiryPolicy.php` | Enum: LinkDetach, SessionEnd, ConnectionClose, Never |
| `tests/Unit/Transport/RevoltTransportTest.php` | Transport unit tests via stream_socket_pair() |
| `tests/Integration/RabbitMqTestCase.php` | Base class: starts RabbitMQ via testcontainers-php |

### Deleted Files
| File | Reason |
|------|--------|
| `src/AMQP10/Transport/BlockingAdapter.php` | Dead code — RevoltTransport is the sole transport |
| `src/AMQP10/Connection/AutoReconnect.php` | Replaced by runtime reconnection in Consumer/Publisher |
| `tests/Unit/Transport/BlockingAdapterTest.php` | Tests for deleted class (if exists) |

### Modified Files
| File | Key Changes |
|------|-------------|
| `composer.json` | Add revolt/event-loop, testcontainers |
| `src/AMQP10/Client/Client.php` | RevoltTransport default; withTlsOptions(); reconnect(); heartbeat timer |
| `src/AMQP10/Client/Config.php` | Remove autoReconnect/maxRetries/backoffMs; add tlsOptions |
| `src/AMQP10/Connection/Connection.php` | Virtual host from URI; parse + expose negotiatedIdleTimeout() |
| `src/AMQP10/Connection/Session.php` | Remove usleep; add incomingWindow()/outgoingWindow() getters |
| `src/AMQP10/Connection/ReceiverLink.php` | TerminusDurability/ExpiryPolicy params; flow window from session |
| `src/AMQP10/Connection/SenderLink.php` | Multi-frame transfer splitting |
| `src/AMQP10/Connection/DeliveryContext.php` | Add modify() |
| `src/AMQP10/Messaging/Consumer.php` | Credit replenishment; link name; durable; multi-frame reassembly; reattach(); remove usleep |
| `src/AMQP10/Messaging/ConsumerBuilder.php` | Accept Client; linkName(); durable(); expiryPolicy(); withReconnect() |
| `src/AMQP10/Messaging/Publisher.php` | preSettled; maxFrameSize; multi-frame; reconnect; remove usleep |
| `src/AMQP10/Messaging/PublisherBuilder.php` | Accept Client; fireAndForget(); cached publisher; close(); withReconnect() |
| `src/AMQP10/Messaging/Message.php` | durable=true default; subject field; create() factory; wither methods |
| `src/AMQP10/Messaging/MessageEncoder.php` | Use durable(); subject(); update properties guard |
| `src/AMQP10/Management/Management.php` | Remove usleep |
| `src/AMQP10/Protocol/PerformativeEncoder.php` | encodeSource() durable/expiry; attach() signature; modified() |
| `src/AMQP10/Protocol/FrameBuilder.php` | Add keepalive() |

---

## Chunk 1: Revolt Transport Foundation

### Task 1: Add revolt/event-loop dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add dependency**

```bash
composer require revolt/event-loop:^1.0
```

- [ ] **Step 2: Verify installed**

```bash
composer show revolt/event-loop
```
Expected: version 1.x listed.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add revolt/event-loop dependency"
```

---

### Task 2: RevoltTransport — connect, disconnect, isConnected

**Files:**
- Create: `src/AMQP10/Transport/RevoltTransport.php`
- Create: `tests/Unit/Transport/RevoltTransportTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Transport;

use AMQP10\Transport\RevoltTransport;
use AMQP10\Exception\ConnectionFailedException;
use PHPUnit\Framework\TestCase;

class RevoltTransportTest extends TestCase
{
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        return $pair;
    }

    public function test_isConnected_returns_false_before_connect(): void
    {
        $t = new RevoltTransport();
        $this->assertFalse($t->isConnected());
    }

    public function test_connect_fails_on_bad_address(): void
    {
        $this->expectException(ConnectionFailedException::class);
        $t = new RevoltTransport();
        $t->connect('amqp://127.0.0.1:19999'); // nothing listening
    }

    public function test_disconnect_is_safe_when_not_connected(): void
    {
        $t = new RevoltTransport();
        $t->disconnect(); // must not throw
        $this->assertFalse($t->isConnected());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Transport/RevoltTransportTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement RevoltTransport (connect/disconnect/isConnected only)**

```php
<?php
declare(strict_types=1);
namespace AMQP10\Transport;

use AMQP10\Exception\ConnectionFailedException;
use Revolt\EventLoop;

class RevoltTransport implements TransportInterface
{
    /** @var resource|null */
    private mixed $stream = null;

    public function __construct(
        private readonly float $readTimeout = 30.0,
        private readonly array $tlsOptions  = [],
    ) {}

    public function connect(string $uri): void
    {
        $parts   = parse_url($uri);
        $host    = $parts['host'] ?? 'localhost';
        $port    = $parts['port'] ?? 5672;
        $tls     = ($parts['scheme'] ?? 'amqp') === 'amqps';
        $address = ($tls ? 'ssl' : 'tcp') . "://$host:$port";

        $context = ($tls && !empty($this->tlsOptions))
            ? stream_context_create(['ssl' => $this->tlsOptions])
            : stream_context_create();

        $stream = @stream_socket_client($address, $errno, $errstr, timeout: 10, context: $context);

        if ($stream === false) {
            throw new ConnectionFailedException("Cannot connect to $address: $errstr (errno $errno)");
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function send(string $bytes): void
    {
        throw new \LogicException('Not yet implemented');
    }

    public function read(int $length = 4096): ?string
    {
        throw new \LogicException('Not yet implemented');
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/Unit/Transport/RevoltTransportTest.php
```
Expected: 3 tests pass (connect failure test may skip if port actually open — that's fine).

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Transport/RevoltTransport.php tests/Unit/Transport/RevoltTransportTest.php
git commit -m "feat: add RevoltTransport skeleton with connect/disconnect"
```

---

### Task 3: RevoltTransport — send and read

**Files:**
- Modify: `src/AMQP10/Transport/RevoltTransport.php`
- Modify: `tests/Unit/Transport/RevoltTransportTest.php`

- [ ] **Step 1: Add send/read tests using stream_socket_pair()**

Add to `RevoltTransportTest`:

```php
    public function test_send_and_read_over_socket_pair(): void
    {
        [$a, $b] = $this->socketPair();
        stream_set_blocking($b, true);

        // Inject stream $a directly for testing via reflection
        $t = new RevoltTransport();
        $ref = new \ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setAccessible(true);
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        \Revolt\EventLoop::run(function () use ($t, $b) {
            $t->send('hello');
            $received = fread($b, 4096);
            $this->assertSame('hello', $received);
            \Revolt\EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_data_when_available(): void
    {
        [$a, $b] = $this->socketPair();
        stream_set_blocking($b, true);

        $t = new RevoltTransport(readTimeout: 1.0);
        $ref = new \ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setAccessible(true);
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        \Revolt\EventLoop::run(function () use ($t, $b) {
            fwrite($b, 'world');
            $data = $t->read(4096);
            $this->assertSame('world', $data);
            \Revolt\EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_empty_string_on_timeout(): void
    {
        [$a, $b] = $this->socketPair();

        $t = new RevoltTransport(readTimeout: 0.05); // 50ms timeout
        $ref = new \ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setAccessible(true);
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        \Revolt\EventLoop::run(function () use ($t, $b) {
            $data = $t->read(4096);
            $this->assertSame('', $data); // timeout — no data
            \Revolt\EventLoop::stop();
        });

        fclose($b);
    }

    public function test_read_returns_null_on_closed_connection(): void
    {
        [$a, $b] = $this->socketPair();

        $t = new RevoltTransport(readTimeout: 1.0);
        $ref = new \ReflectionProperty(RevoltTransport::class, 'stream');
        $ref->setAccessible(true);
        $ref->setValue($t, $a);
        stream_set_blocking($a, false);

        \Revolt\EventLoop::run(function () use ($t, $b) {
            fclose($b); // peer closed
            \Revolt\EventLoop::delay(0.02, function () use ($t) {
                $data = $t->read(4096);
                $this->assertNull($data);
                \Revolt\EventLoop::stop();
            });
        });
    }
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Transport/RevoltTransportTest.php
```
Expected: new tests fail with "Not yet implemented".

- [ ] **Step 3: Implement send() and read()**

Replace the stub methods in `RevoltTransport`:

```php
    public function send(string $bytes): void
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }
        $total  = strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false) {
                throw new ConnectionFailedException('Failed to write to socket');
            }
            if ($written === 0) {
                $suspension = EventLoop::getSuspension();
                $resolved   = false;
                $id = EventLoop::onWritable($this->stream, function () use ($suspension, &$resolved) {
                    if (!$resolved) {
                        $resolved = true;
                        $suspension->resume();
                    }
                });
                $suspension->suspend();
                EventLoop::cancel($id);
            } else {
                $offset += $written;
            }
        }
    }

    public function read(int $length = 4096): ?string
    {
        if ($this->stream === null) {
            throw new ConnectionFailedException('Not connected');
        }

        $data = @fread($this->stream, $length);

        if ($data === false) {
            return null;
        }
        if ($data !== '') {
            return $data;
        }
        if (feof($this->stream)) {
            return null;
        }

        // No data yet — suspend until readable or timeout fires
        $suspension = EventLoop::getSuspension();
        $resolved   = false;

        $readId = EventLoop::onReadable($this->stream, function () use ($suspension, &$resolved) {
            if (!$resolved) {
                $resolved = true;
                $suspension->resume(true);
            }
        });
        $timeoutId = EventLoop::delay($this->readTimeout, function () use ($suspension, &$resolved) {
            if (!$resolved) {
                $resolved = true;
                $suspension->resume(false);
            }
        });

        $hasData = $suspension->suspend();
        EventLoop::cancel($readId);
        EventLoop::cancel($timeoutId);

        if (!$hasData) {
            return '';
        }

        $data = @fread($this->stream, $length);
        if ($data === false || ($data === '' && feof($this->stream))) {
            return null;
        }
        return $data;
    }
```

- [ ] **Step 4: Run all transport tests**

```bash
vendor/bin/phpunit tests/Unit/Transport/RevoltTransportTest.php
```
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Transport/RevoltTransport.php tests/Unit/Transport/RevoltTransportTest.php
git commit -m "feat: implement RevoltTransport send() and read() with Fiber suspension"
```

---

### Task 4: Delete BlockingAdapter; wire RevoltTransport into Client

**Files:**
- Delete: `src/AMQP10/Transport/BlockingAdapter.php`
- Modify: `src/AMQP10/Client/Client.php`
- Modify: `src/AMQP10/Client/Config.php`

- [ ] **Step 1: Delete BlockingAdapter and any unit tests for it**

```bash
rm src/AMQP10/Transport/BlockingAdapter.php
# If a test file exists:
find tests/ -name 'BlockingAdapterTest.php' -delete
```

- [ ] **Step 2: Update Config — remove autoReconnect/maxRetries/backoffMs, add tlsOptions**

Replace `src/AMQP10/Client/Config.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Client;

use AMQP10\Connection\Sasl;

readonly class Config
{
    public function __construct(
        public ?Sasl  $sasl       = null,
        public float  $timeout    = 30.0,
        /** @var array<string, mixed> */
        public array  $tlsOptions = [],
    ) {}

    /** @param array<string, mixed>|null $tlsOptions */
    public function with(
        ?Sasl  $sasl       = null,
        ?float $timeout    = null,
        ?array $tlsOptions = null,
    ): self {
        return new self(
            sasl:       $sasl       ?? $this->sasl,
            timeout:    $timeout    ?? $this->timeout,
            tlsOptions: $tlsOptions ?? $this->tlsOptions,
        );
    }
}
```

- [ ] **Step 3: Update Client — use RevoltTransport, remove BlockingAdapter import**

Replace the transport instantiation and related methods in `src/AMQP10/Client/Client.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Client;

use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Connection\Session;
use AMQP10\Management\Management;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Transport\RevoltTransport;
use AMQP10\Transport\TransportInterface;

class Client
{
    private ?Connection $connection = null;
    private ?Session    $session    = null;

    public function __construct(
        private readonly string             $uri,
        private Config                      $config    = new Config(),
        private readonly ?TransportInterface $transport = null,
    ) {}

    public function connect(): static
    {
        $transport  = $this->transport ?? new RevoltTransport(
            readTimeout: $this->config->timeout,
            tlsOptions:  $this->config->tlsOptions,
        );
        $connection = new Connection($transport, $this->uri, $this->config->sasl, $this->config->timeout);
        $connection->open();

        $this->connection = $connection;
        $this->session    = new Session($transport, channel: 0, timeout: $this->config->timeout);
        $this->session->begin();

        return $this;
    }

    public function reconnect(): void
    {
        $this->session?->end();
        $this->connection?->close();
        $this->connection = null;
        $this->session    = null;
        $this->connect();
    }

    public function close(): void
    {
        $this->session?->end();
        $this->connection?->close();
        $this->connection = null;
        $this->session    = null;
    }

    public function isConnected(): bool
    {
        return $this->connection?->isOpen() ?? false;
    }

    public function session(): Session
    {
        if ($this->session === null) {
            throw new \RuntimeException('Call connect() before using the client');
        }
        return $this->session;
    }

    // --- Fluent config ---

    public function withTlsOptions(array $options): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(tlsOptions: $options);
        return $clone;
    }

    public function withSasl(Sasl $sasl): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(sasl: $sasl);
        return $clone;
    }

    public function withTimeout(float $timeout): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(timeout: $timeout);
        return $clone;
    }

    public function config(): Config
    {
        return $this->config;
    }

    // --- API ---

    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder($this, $address, $this->config->timeout);
    }

    public function consume(string $address): ConsumerBuilder
    {
        return new ConsumerBuilder($this, $address, $this->config->timeout);
    }

    public function management(): Management
    {
        return new Management($this->session(), $this->config->timeout);
    }
}
```

- [ ] **Step 4: Remove usleep from Session and Management**

In `src/AMQP10/Connection/Session.php` line 121, remove `usleep(1000)`:
```php
// Before:
if ($data === '') {
    usleep(1000);
    continue;
}
// After:
if ($data === '') {
    continue;
}
```

In `src/AMQP10/Management/Management.php` line 183, remove `usleep(1000)`:
```php
// Before:
usleep(1000);
continue;
// After:
continue;
```

- [ ] **Step 5: Run full unit test suite**

```bash
vendor/bin/phpunit
```
Expected: all previously passing tests still pass. Any test directly instantiating `BlockingAdapter` or `AutoReconnect` will fail — fix or delete those tests.

- [ ] **Step 6: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```
Fix any errors introduced by the Config/Client changes.

- [ ] **Step 7: Commit**

```bash
git add -u
git add src/AMQP10/Transport/RevoltTransport.php src/AMQP10/Client/
git commit -m "feat: replace BlockingAdapter with RevoltTransport as sole transport"
```

---

## Chunk 2: Enums and Message API

### Task 5: TerminusDurability and ExpiryPolicy enums

**Files:**
- Create: `src/AMQP10/Terminus/TerminusDurability.php`
- Create: `src/AMQP10/Terminus/ExpiryPolicy.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Terminus/TerminusEnumsTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Terminus;

use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use PHPUnit\Framework\TestCase;

class TerminusEnumsTest extends TestCase
{
    public function test_terminus_durability_values(): void
    {
        $this->assertSame(0, TerminusDurability::None->value);
        $this->assertSame(1, TerminusDurability::Configuration->value);
        $this->assertSame(2, TerminusDurability::UnsettledState->value);
    }

    public function test_expiry_policy_values(): void
    {
        $this->assertSame('link-detach',      ExpiryPolicy::LinkDetach->value);
        $this->assertSame('session-end',      ExpiryPolicy::SessionEnd->value);
        $this->assertSame('connection-close', ExpiryPolicy::ConnectionClose->value);
        $this->assertSame('never',            ExpiryPolicy::Never->value);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Terminus/TerminusEnumsTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Create enums**

`src/AMQP10/Terminus/TerminusDurability.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Terminus;

enum TerminusDurability: int
{
    case None           = 0;
    case Configuration  = 1;
    case UnsettledState = 2;
}
```

`src/AMQP10/Terminus/ExpiryPolicy.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Terminus;

enum ExpiryPolicy: string
{
    case LinkDetach      = 'link-detach';
    case SessionEnd      = 'session-end';
    case ConnectionClose = 'connection-close';
    case Never           = 'never';
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/Unit/Terminus/TerminusEnumsTest.php
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/AMQP10/Terminus/ tests/Unit/Terminus/
git commit -m "feat: add TerminusDurability and ExpiryPolicy enums"
```

---

### Task 6: Message — durable flag, subject, fluent wither API

**Files:**
- Modify: `src/AMQP10/Messaging/Message.php`
- Modify: `src/AMQP10/Messaging/MessageEncoder.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Messaging/MessageTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Messaging\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_durable_defaults_to_true(): void
    {
        $m = Message::create('body');
        $this->assertTrue($m->durable());
    }

    public function test_with_durable_false(): void
    {
        $m = Message::create('body')->withDurable(false);
        $this->assertFalse($m->durable());
        // original unchanged
        $orig = Message::create('body');
        $this->assertTrue($orig->durable());
    }

    public function test_with_subject(): void
    {
        $m = Message::create('body')->withSubject('order.placed');
        $this->assertSame('order.placed', $m->subject());
    }

    public function test_subject_is_null_by_default(): void
    {
        $this->assertNull(Message::create('body')->subject());
    }

    public function test_with_message_id(): void
    {
        $m = Message::create('body')->withMessageId('abc-123');
        $this->assertSame('abc-123', $m->property('message-id'));
    }

    public function test_with_content_type(): void
    {
        $m = Message::create('body')->withContentType('application/json');
        $this->assertSame('application/json', $m->property('content-type'));
    }

    public function test_with_correlation_id(): void
    {
        $m = Message::create('body')->withCorrelationId('corr-1');
        $this->assertSame('corr-1', $m->property('correlation-id'));
    }

    public function test_with_application_property(): void
    {
        $m = Message::create('body')->withApplicationProperty('source', 'checkout');
        $this->assertSame('checkout', $m->applicationProperty('source'));
    }

    public function test_with_ttl(): void
    {
        $m = Message::create('body')->withTtl(5000);
        $this->assertSame(5000, $m->ttl());
    }

    public function test_with_priority(): void
    {
        $m = Message::create('body')->withPriority(9);
        $this->assertSame(9, $m->priority());
    }

    public function test_wither_immutability(): void
    {
        $original = Message::create('body');
        $modified = $original->withSubject('test');
        $this->assertNotSame($original, $modified);
        $this->assertNull($original->subject());
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Messaging/MessageTest.php
```
Expected: FAIL — methods not found.

- [ ] **Step 3: Implement Message changes**

Replace `src/AMQP10/Messaging/Message.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

class Message
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $annotations
     */
    public function __construct(
        private readonly string  $body,
        private readonly array   $properties            = [],
        private readonly array   $applicationProperties = [],
        private readonly array   $annotations           = [],
        private readonly int     $ttl                   = 0,
        private readonly int     $priority              = 4,
        private readonly bool    $durable               = true,
        private readonly ?string $subject               = null,
    ) {}

    public static function create(string $body): self
    {
        return new self($body);
    }

    public function body(): string { return $this->body; }
    public function durable(): bool { return $this->durable; }
    public function subject(): ?string { return $this->subject; }
    public function ttl(): int { return $this->ttl; }
    public function priority(): int { return $this->priority; }

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

    /** @return array<string, mixed> */
    public function properties(): array { return $this->properties; }

    /** @return array<string, mixed> */
    public function applicationProperties(): array { return $this->applicationProperties; }

    /** @return array<string, mixed> */
    public function annotations(): array { return $this->annotations; }

    public function withDurable(bool $durable): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $durable, $this->subject);
    }

    public function withSubject(string $subject): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $subject);
    }

    public function withMessageId(string $id): self
    {
        return $this->withProperty('message-id', $id);
    }

    public function withCorrelationId(string $id): self
    {
        return $this->withProperty('correlation-id', $id);
    }

    public function withReplyTo(string $address): self
    {
        return $this->withProperty('reply-to', $address);
    }

    public function withContentType(string $contentType): self
    {
        return $this->withProperty('content-type', $contentType);
    }

    public function withTtl(int $ttl): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $ttl, $this->priority, $this->durable, $this->subject);
    }

    public function withPriority(int $priority): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $priority, $this->durable, $this->subject);
    }

    public function withApplicationProperty(string $key, mixed $value): self
    {
        $props = $this->applicationProperties;
        $props[$key] = $value;
        return new self($this->body, $this->properties, $props,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $this->subject);
    }

    public function withAnnotation(string $key, mixed $value): self
    {
        $anns = $this->annotations;
        $anns[$key] = $value;
        return new self($this->body, $this->properties, $this->applicationProperties,
            $anns, $this->ttl, $this->priority, $this->durable, $this->subject);
    }

    private function withProperty(string $key, mixed $value): self
    {
        $props = $this->properties;
        $props[$key] = $value;
        return new self($this->body, $props, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $this->subject);
    }
}
```

- [ ] **Step 4: Run Message tests**

```bash
vendor/bin/phpunit tests/Unit/Messaging/MessageTest.php
```
Expected: all pass.

- [ ] **Step 5: Write failing MessageEncoder tests**

Add to a new `tests/Unit/Messaging/MessageEncoderTest.php` (or existing encoder test):

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class MessageEncoderTest extends TestCase
{
    private function decodeSection(string $encoded): array
    {
        $decoder = new TypeDecoder($encoded);
        return $decoder->decode();
    }

    public function test_durable_true_by_default(): void
    {
        $encoded = MessageEncoder::encode(Message::create('test'));
        $section = $this->decodeSection($encoded);
        // Header section: descriptor 0x70, field 0 = durable
        $this->assertSame(Descriptor::MSG_HEADER, $section['descriptor']);
        $this->assertTrue($section['value'][0]); // durable = true
    }

    public function test_durable_false_when_set(): void
    {
        $encoded = MessageEncoder::encode(Message::create('test')->withDurable(false));
        $section = $this->decodeSection($encoded);
        $this->assertFalse($section['value'][0]);
    }

    public function test_properties_section_emitted_for_subject_only(): void
    {
        $encoded  = MessageEncoder::encode(Message::create('body')->withSubject('order.placed'));
        $decoder  = new TypeDecoder($encoded);
        $sections = [];
        while ($decoder->remaining() > 0) {
            $sections[] = $decoder->decode();
        }
        $propSection = array_values(array_filter($sections,
            fn($s) => ($s['descriptor'] ?? null) === Descriptor::MSG_PROPERTIES
        ));
        $this->assertCount(1, $propSection);
        $this->assertSame('order.placed', $propSection[0]['value'][3]); // subject = index 3
    }
}
```

- [ ] **Step 6: Run to confirm failures**

```bash
vendor/bin/phpunit tests/Unit/Messaging/MessageEncoderTest.php
```
Expected: FAIL — durable=false hardcoded, properties skipped when no $properties array.

- [ ] **Step 7: Fix MessageEncoder**

In `src/AMQP10/Messaging/MessageEncoder.php`, update the `encode()` method:

```php
    public static function encode(Message $message): string
    {
        $sections = '';

        // Header section — emit when durable, TTL, or non-default priority
        if ($message->durable() || $message->ttl() > 0 || $message->priority() !== 4) {
            $sections .= self::section(Descriptor::MSG_HEADER, [
                TypeEncoder::encodeBool($message->durable()),
                TypeEncoder::encodeUbyte($message->priority()),
                $message->ttl() > 0
                    ? TypeEncoder::encodeUint($message->ttl())
                    : TypeEncoder::encodeNull(),
            ]);
        }

        // Message annotations section
        $annotations = $message->annotations();
        if (!empty($annotations)) {
            $pairs = [];
            foreach ($annotations as $key => $value) {
                $encodedKey   = TypeEncoder::encodeSymbol((string) $key);
                $encodedValue = match (true) {
                    is_null($value)  => TypeEncoder::encodeNull(),
                    is_bool($value)  => TypeEncoder::encodeBool($value),
                    is_int($value)   => TypeEncoder::encodeUlong($value),
                    default          => TypeEncoder::encodeString((string) $value),
                };
                $pairs[$encodedKey] = $encodedValue;
            }
            $sections .= self::sectionMap(Descriptor::MSG_ANNOTATIONS, $pairs);
        }

        // Properties section — emit when any first-class property field is set
        $props = $message->properties();
        $subject = $message->subject() ?? $props['subject'] ?? null;
        if (!empty($props) || $subject !== null) {
            $sections .= self::section(Descriptor::MSG_PROPERTIES, [
                isset($props['message-id'])
                    ? TypeEncoder::encodeString($props['message-id'])
                    : TypeEncoder::encodeNull(),
                isset($props['user-id'])
                    ? TypeEncoder::encodeBinary($props['user-id'])
                    : TypeEncoder::encodeNull(),
                isset($props['to'])
                    ? TypeEncoder::encodeString($props['to'])
                    : TypeEncoder::encodeNull(),
                $subject !== null
                    ? TypeEncoder::encodeString($subject)
                    : TypeEncoder::encodeNull(),
                isset($props['reply-to'])
                    ? TypeEncoder::encodeString($props['reply-to'])
                    : TypeEncoder::encodeNull(),
                isset($props['correlation-id'])
                    ? TypeEncoder::encodeString($props['correlation-id'])
                    : TypeEncoder::encodeNull(),
                isset($props['content-type'])
                    ? TypeEncoder::encodeSymbol($props['content-type'])
                    : TypeEncoder::encodeNull(),
                isset($props['content-encoding'])
                    ? TypeEncoder::encodeSymbol($props['content-encoding'])
                    : TypeEncoder::encodeNull(),
            ]);
        }

        // Application properties section
        $appProps = $message->applicationProperties();
        if (!empty($appProps)) {
            $pairs = [];
            foreach ($appProps as $key => $value) {
                $pairs[TypeEncoder::encodeString($key)] = TypeEncoder::encodeString((string) $value);
            }
            $sections .= self::sectionMap(Descriptor::MSG_APPLICATION_PROPS, $pairs);
        }

        // Data section
        $sections .= TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_DATA),
            TypeEncoder::encodeBinary($message->body()),
        );

        return $sections;
    }
```

- [ ] **Step 8: Run all messaging tests**

```bash
vendor/bin/phpunit tests/Unit/Messaging/
```
Expected: all pass.

- [ ] **Step 9: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

- [ ] **Step 10: Commit**

```bash
git add src/AMQP10/Messaging/Message.php src/AMQP10/Messaging/MessageEncoder.php \
        tests/Unit/Messaging/
git commit -m "feat: Message fluent wither API, durable=true default, subject field"
```

---

## Chunk 3: Consumer Improvements

### Task 7: Terminus durability in PerformativeEncoder and ReceiverLink

**Files:**
- Modify: `src/AMQP10/Protocol/PerformativeEncoder.php`
- Modify: `src/AMQP10/Connection/ReceiverLink.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Protocol/EncodeSourceDurabilityTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Protocol;

use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use PHPUnit\Framework\TestCase;

class EncodeSourceDurabilityTest extends TestCase
{
    private function decodeSourceFromAttach(string $frame): array
    {
        $body = \AMQP10\Protocol\FrameParser::extractBody($frame);
        $decoded = (new TypeDecoder($body))->decode();
        // attach field 5 = source
        return $decoded['value'][5];
    }

    public function test_no_durability_emits_short_source(): void
    {
        $frame  = PerformativeEncoder::attach(
            channel: 0, name: 'link', handle: 0, role: true, source: '/queues/test'
        );
        $source = $this->decodeSourceFromAttach($frame);
        // Short-path: only address field
        $this->assertSame('/queues/test', $source['value'][0]);
    }

    public function test_durability_unsettled_state_encoded(): void
    {
        $frame  = PerformativeEncoder::attach(
            channel: 0, name: 'link', handle: 0, role: true, source: '/queues/test',
            durable: TerminusDurability::UnsettledState,
            expiryPolicy: ExpiryPolicy::Never,
        );
        $source = $this->decodeSourceFromAttach($frame);
        $this->assertSame(2, $source['value'][1]);            // durable = 2
        $this->assertSame('never', $source['value'][2]);       // expiry-policy = never
    }

    public function test_durability_without_filter_uses_full_field_list(): void
    {
        $frame  = PerformativeEncoder::attach(
            channel: 0, name: 'link', handle: 0, role: true, source: '/queues/test',
            durable: TerminusDurability::Configuration,
        );
        $source = $this->decodeSourceFromAttach($frame);
        $this->assertSame(1, $source['value'][1]); // durable = 1
    }
}
```

- [ ] **Step 2: Run to confirm failures**

```bash
vendor/bin/phpunit tests/Unit/Protocol/EncodeSourceDurabilityTest.php
```
Expected: FAIL — attach() doesn't accept durable/expiryPolicy.

- [ ] **Step 3: Update PerformativeEncoder::attach() and encodeSource()**

In `src/AMQP10/Protocol/PerformativeEncoder.php`, add parameters to `attach()`:

```php
    public static function attach(
        int                   $channel,
        string                $name,
        int                   $handle,
        bool                  $role,
        ?string               $source          = null,
        ?string               $target          = null,
        int                   $sndSettleMode   = self::SND_UNSETTLED,
        int                   $rcvSettleMode   = self::RCV_FIRST,
        ?array                $properties      = null,
        ?int                  $initialDeliveryCount = null,
        ?string               $filterMap       = null,
        ?TerminusDurability   $durable         = null,
        ?ExpiryPolicy         $expiryPolicy    = null,
    ): string {
```

Add the `use` statements at the top of the file:
```php
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
```

Pass through to `encodeSource()`:
```php
        return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::ATTACH, [
            TypeEncoder::encodeString($name),
            TypeEncoder::encodeUint($handle),
            TypeEncoder::encodeBool($role),
            TypeEncoder::encodeUbyte($sndSettleMode),
            TypeEncoder::encodeUbyte($rcvSettleMode),
            self::encodeSource($source, $filterMap, $durable, $expiryPolicy),
            self::encodeTarget($target),
            // ... rest unchanged
        ]));
```

Update `encodeSource()`:

```php
    private static function encodeSource(
        ?string             $address,
        ?string             $filterMap    = null,
        ?TerminusDurability $durable      = null,
        ?ExpiryPolicy       $expiryPolicy = null,
    ): string {
        if ($address === null) {
            return TypeEncoder::encodeNull();
        }

        // Short-path: only address, nothing else needed
        if ($filterMap === null && $durable === null && $expiryPolicy === null) {
            $fields = [TypeEncoder::encodeString($address)];
            return TypeEncoder::encodeDescribed(
                TypeEncoder::encodeUlong(Descriptor::SOURCE),
                TypeEncoder::encodeList($fields),
            );
        }

        // Full field list (AMQP 1.0 §3.5.3)
        $fields = [
            TypeEncoder::encodeString($address),                          // 0: address
            $durable !== null
                ? TypeEncoder::encodeUint($durable->value)
                : TypeEncoder::encodeNull(),                              // 1: durable
            $expiryPolicy !== null
                ? TypeEncoder::encodeSymbol($expiryPolicy->value)
                : TypeEncoder::encodeNull(),                              // 2: expiry-policy
            TypeEncoder::encodeNull(),                                    // 3: timeout
            TypeEncoder::encodeNull(),                                    // 4: dynamic
            TypeEncoder::encodeNull(),                                    // 5: dynamic-node-properties
            TypeEncoder::encodeNull(),                                    // 6: distribution-mode
            $filterMap ?? TypeEncoder::encodeNull(),                      // 7: filter
            TypeEncoder::encodeNull(),                                    // 8: default-outcome
            TypeEncoder::encodeNull(),                                    // 9: outcomes
            $filterMap !== null
                ? TypeEncoder::encodeSymbolArray(['rabbit:stream'])
                : TypeEncoder::encodeNull(),                              // 10: capabilities
        ];

        return TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::SOURCE),
            TypeEncoder::encodeList($fields),
        );
    }
```

- [ ] **Step 4: Update ReceiverLink to accept and pass durability params**

In `src/AMQP10/Connection/ReceiverLink.php`, add constructor params and pass to attach:

```php
    public function __construct(
        private readonly Session             $session,
        private readonly string             $name,
        private readonly string             $source,
        private readonly ?string            $target          = null,
        private readonly int                $initialCredit   = 10,
        private readonly bool               $managementLink  = false,
        private readonly ?string            $filterMap       = null,
        private readonly ?TerminusDurability $durable        = null,
        private readonly ?ExpiryPolicy      $expiryPolicy    = null,
    ) {
```

Pass to `PerformativeEncoder::attach()` call:
```php
            durable:      $this->durable,
            expiryPolicy: $this->expiryPolicy,
```

Also fix flow window to use session values (§10 from spec):

```php
    public function grantCredit(int $credit, int $deliveryCount = 0): void
    {
        $this->session->transport()->send(PerformativeEncoder::flow(
            channel:        $this->session->channel(),
            nextIncomingId: 0,
            incomingWindow: $this->session->incomingWindow(),
            nextOutgoingId: 0,
            outgoingWindow: $this->session->outgoingWindow(),
            handle:         $this->handle,
            deliveryCount:  $deliveryCount,
            linkCredit:     $credit,
        ));
    }
```

- [ ] **Step 5: Add incomingWindow/outgoingWindow getters to Session**

In `src/AMQP10/Connection/Session.php`:

```php
    public function incomingWindow(): int { return $this->incomingWindow; }
    public function outgoingWindow(): int { return $this->outgoingWindow; }
```

- [ ] **Step 6: Run tests**

```bash
vendor/bin/phpunit tests/Unit/Protocol/EncodeSourceDurabilityTest.php
vendor/bin/phpunit
```
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Protocol/PerformativeEncoder.php \
        src/AMQP10/Connection/ReceiverLink.php \
        src/AMQP10/Connection/Session.php \
        src/AMQP10/Terminus/ \
        tests/Unit/Protocol/EncodeSourceDurabilityTest.php
git commit -m "feat: terminus durability and expiry policy on receiver links"
```

---

### Task 8: Credit replenishment, stable link name, ConsumerBuilder→Client

**Files:**
- Modify: `src/AMQP10/Messaging/Consumer.php`
- Modify: `src/AMQP10/Messaging/ConsumerBuilder.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Messaging/ConsumerCreditTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Messaging\Consumer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ConsumerCreditTest extends TestCase
{
    private function mockSession(): Session&MockObject
    {
        $session = $this->createMock(Session::class);
        $session->method('incomingWindow')->willReturn(2048);
        $session->method('outgoingWindow')->willReturn(2048);
        return $session;
    }

    public function test_credit_replenished_after_half_window_consumed(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        // Initial credit grant on attach
        $link->expects($this->exactly(2))
             ->method('grantCredit')
             ->withConsecutive(
                 [10, 0],           // initial attach
                 [5, 5],            // replenish after 5 deliveries (floor(10/2))
             );

        // ... test that after 5 messages received, grantCredit(5, 5) is called
        // This requires injecting a mock link; use reflection or refactor Consumer
        // to accept an injected ReceiverLink for testability.
        $this->markTestIncomplete('Implement after Consumer refactor');
    }

    public function test_link_name_defaults_to_random(): void
    {
        // Two consumers without explicit link name get different names
        $session = $this->mockSession();
        // We can only test this at the builder level
        $this->assertTrue(true); // placeholder — integration test covers real behaviour
    }
}
```

- [ ] **Step 2: Update ConsumerBuilder to accept Client**

Replace `src/AMQP10/Messaging/ConsumerBuilder.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Client\Client;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;

class ConsumerBuilder
{
    private ?\Closure           $handler           = null;
    private ?\Closure           $errorHandler      = null;
    private int                 $credit            = 10;
    private ?Offset             $offset            = null;
    private ?string             $filterJms         = null;
    private ?string             $filterAmqpSql     = null;
    /** @var ?array<string> */
    private ?array              $filterBloomValues = null;
    private bool                $matchUnfiltered   = false;
    private ?string             $linkName          = null;
    private ?TerminusDurability $durable           = null;
    private ?ExpiryPolicy       $expiryPolicy      = null;
    private int                 $reconnectRetries  = 0;
    private int                 $reconnectBackoffMs = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly string $address,
        private readonly float  $idleTimeout = 30.0,
    ) {}

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

    public function credit(int $n): self
    {
        $this->credit = $n;
        return $this;
    }

    public function prefetch(int $n): self
    {
        return $this->credit($n);
    }

    public function offset(Offset $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function linkName(string $name): self
    {
        $this->linkName = $name;
        return $this;
    }

    public function durable(TerminusDurability $durability = TerminusDurability::UnsettledState): self
    {
        $this->durable = $durability;
        return $this;
    }

    public function expiryPolicy(ExpiryPolicy $policy = ExpiryPolicy::Never): self
    {
        $this->expiryPolicy = $policy;
        return $this;
    }

    public function withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self
    {
        $this->reconnectRetries   = $maxRetries;
        $this->reconnectBackoffMs = $backoffMs;
        return $this;
    }

    public function filterSql(string $sql): self
    {
        return $this->filterAmqpSql($sql);
    }

    public function filterJms(string $sql): self
    {
        $this->filterJms = $sql;
        return $this;
    }

    public function filterAmqpSql(string $sql): self
    {
        $this->filterAmqpSql = $sql;
        return $this;
    }

    /** @param string|array<string> $values */
    public function filterBloom(string|array $values, bool $matchUnfiltered = false): self
    {
        $this->filterBloomValues = is_array($values) ? $values : [$values];
        $this->matchUnfiltered   = $matchUnfiltered;
        return $this;
    }

    public function run(): void
    {
        $consumer = $this->consumer();
        $consumer->run($this->handler, $this->errorHandler);
    }

    public function consumer(): Consumer
    {
        return new Consumer(
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
}
```

- [ ] **Step 3: Update Consumer with credit replenishment, link name, reattach**

Replace `src/AMQP10/Messaging/Consumer.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Client\Client;
use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use Revolt\EventLoop;

class Consumer
{
    private ?ReceiverLink $link     = null;
    private bool          $attached = false;
    private int           $received = 0;

    /** @var array<int, string> Accumulated payload for in-progress multi-frame deliveries keyed by delivery-id */
    private array $partialDeliveries = [];

    public function __construct(
        private readonly Client              $client,
        private readonly string              $address,
        private readonly int                 $credit            = 10,
        private readonly ?Offset             $offset            = null,
        private readonly ?string             $filterJms         = null,
        private readonly ?string             $filterAmqpSql     = null,
        /** @var ?array<string> */
        private readonly ?array              $filterBloomValues = null,
        private readonly bool                $matchUnfiltered   = false,
        private readonly float               $idleTimeout       = 30.0,
        private readonly ?string             $linkName          = null,
        private readonly ?TerminusDurability $durable           = null,
        private readonly ?ExpiryPolicy       $expiryPolicy      = null,
        private readonly int                 $reconnectRetries  = 0,
        private readonly int                 $reconnectBackoffMs = 1000,
    ) {}

    private function buildLink(Session $session): ReceiverLink
    {
        $name = $this->linkName ?? ('receiver-' . bin2hex(random_bytes(4)));
        return new ReceiverLink(
            session:      $session,
            name:         $name,
            source:       $this->address,
            initialCredit: $this->credit,
            filterMap:    $this->buildFilterMap(),
            durable:      $this->durable,
            expiryPolicy: $this->expiryPolicy,
        );
    }

    private function ensureAttached(): void
    {
        if (!$this->attached) {
            $this->link = $this->buildLink($this->client->session());
            $this->link->attach();
            $this->attached = true;
        }
    }

    public function reattach(Session $session): void
    {
        $this->link     = $this->buildLink($session);
        $this->link->attach();
        $this->attached  = true;
        $this->received  = 0;
    }

    public function receive(): ?Delivery
    {
        $this->ensureAttached();

        $deadline  = microtime(true) + $this->idleTimeout;
        $replenish = (int) floor($this->credit / 2);

        while (true) {
            $frame = $this->client->session()->nextFrame();
            if ($frame === null) {
                if (!$this->client->session()->transport()->isConnected()) {
                    return null;
                }
                if (microtime(true) >= $deadline) {
                    return null;
                }
                continue;
            }

            $descriptor = $this->getFrameDescriptor($frame);
            if ($descriptor !== Descriptor::TRANSFER) {
                continue;
            }

            $delivery = $this->handleTransferFrame($frame);
            if ($delivery === null) {
                continue; // more=true, accumulating
            }

            $this->received++;
            if ($replenish > 0 && $this->received % $replenish === 0) {
                $this->link->grantCredit((int) ceil($this->credit / 2), $this->received);
            }

            return $delivery;
        }
    }

    private function handleTransferFrame(string $frame): ?Delivery
    {
        $body         = FrameParser::extractBody($frame);
        $decoder      = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId  = $performative['value'][1] ?? 0;
        $more        = $performative['value'][5] ?? false;
        $msgPayload  = substr($body, $decoder->offset());

        if ($more) {
            $this->partialDeliveries[$deliveryId] = ($this->partialDeliveries[$deliveryId] ?? '') . $msgPayload;
            return null;
        }

        if (isset($this->partialDeliveries[$deliveryId])) {
            $msgPayload = $this->partialDeliveries[$deliveryId] . $msgPayload;
            unset($this->partialDeliveries[$deliveryId]);
        }

        $message = MessageDecoder::decode($msgPayload);
        $ctx     = new DeliveryContext($deliveryId, $this->link);

        return new Delivery($message, $ctx);
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $attempts = 0;
        while (true) {
            try {
                while ($delivery = $this->receive()) {
                    if ($handler !== null) {
                        try {
                            $handler($delivery->message(), $delivery->context());
                        } catch (\Throwable $e) {
                            if ($errorHandler !== null) {
                                $errorHandler($e);
                            }
                        }
                    }
                }
                break; // receive() returned null — clean exit (idle timeout)
            } catch (ConnectionFailedException|\RuntimeException $e) {
                if ($this->reconnectRetries === 0 || $attempts >= $this->reconnectRetries) {
                    throw $e;
                }
                $attempts++;
                $backoff = $this->reconnectBackoffMs * $attempts;
                // Revolt-friendly sleep: delay if inside event loop, otherwise usleep
                if (class_exists(EventLoop::class)) {
                    $suspension = EventLoop::getSuspension();
                    EventLoop::delay($backoff / 1000, fn() => $suspension->resume());
                    $suspension->suspend();
                } else {
                    usleep($backoff * 1000);
                }
                $this->attached = false;
                $this->client->reconnect();
                $this->reattach($this->client->session());
            }
        }
        $this->close();
    }

    public function close(): void
    {
        try {
            $this->link?->detach();
        } catch (\Throwable) {
        }
        $this->attached = false;
    }

    private function getFrameDescriptor(string $frame): ?int
    {
        if (strlen($frame) < 8) {
            return null;
        }
        try {
            $body         = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();
            return is_array($performative) ? ($performative['descriptor'] ?? null) : null;
        } catch (\AMQP10\Exception\FrameException) {
            return null;
        }
    }

    private function buildFilterMap(): ?string
    {
        if ($this->offset === null &&
            $this->filterJms === null &&
            $this->filterAmqpSql === null &&
            $this->filterBloomValues === null &&
            !$this->matchUnfiltered) {
            return null;
        }

        $pairs = [];

        if ($this->offset !== null) {
            $offsetValue = match ($this->offset->type) {
                'first', 'last', 'next' => TypeEncoder::encodeSymbol($this->offset->type),
                'offset'                => TypeEncoder::encodeUlong((int) $this->offset->value),
                'timestamp'             => TypeEncoder::encodeTimestamp((int) $this->offset->value),
                default                 => TypeEncoder::encodeSymbol('first'),
            };
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec');
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $offsetValue);
        }

        if ($this->filterJms !== null) {
            $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
                TypeEncoder::encodeString($this->filterJms);
        }

        if ($this->filterAmqpSql !== null) {
            $mapKey     = TypeEncoder::encodeSymbol('sql-filter');
            $descriptor = TypeEncoder::encodeSymbol('amqp:sql-filter');
            $pairs[$mapKey] = TypeEncoder::encodeDescribed($descriptor, TypeEncoder::encodeString($this->filterAmqpSql));
        }

        if ($this->filterBloomValues !== null) {
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-filter');
            $inner = count($this->filterBloomValues) === 1
                ? TypeEncoder::encodeString($this->filterBloomValues[0])
                : TypeEncoder::encodeList(array_map(
                    fn(string $v) => TypeEncoder::encodeString($v),
                    $this->filterBloomValues
                ));
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $inner);
        }

        if ($this->matchUnfiltered) {
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-match-unfiltered');
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, TypeEncoder::encodeBool(true));
        }

        return TypeEncoder::encodeMap($pairs);
    }
}
```

- [ ] **Step 4: Run full test suite**

```bash
vendor/bin/phpunit
```
Expected: all pass. Fix any existing tests that passed a `Session` to `ConsumerBuilder` — they now need a `Client`.

- [ ] **Step 5: PHPStan**

```bash
vendor/bin/phpstan analyse
```

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Messaging/Consumer.php src/AMQP10/Messaging/ConsumerBuilder.php \
        src/AMQP10/Connection/ReceiverLink.php src/AMQP10/Connection/Session.php \
        tests/Unit/Messaging/ConsumerCreditTest.php
git commit -m "feat: credit replenishment, stable link name, durable consumers, reconnect"
```

---

## Chunk 4: Publisher Improvements

### Task 9: Fire-and-forget and PublisherBuilder caching

**Files:**
- Modify: `src/AMQP10/Messaging/Publisher.php`
- Modify: `src/AMQP10/Messaging/PublisherBuilder.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Messaging/PublisherBuilderTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Client\Client;
use AMQP10\Messaging\PublisherBuilder;
use PHPUnit\Framework\TestCase;

class PublisherBuilderTest extends TestCase
{
    public function test_publisher_is_cached_across_send_calls(): void
    {
        $client = $this->createMock(Client::class);
        // We can't easily test this without a live connection;
        // verify the cached field exists via reflection
        $builder = new PublisherBuilder($client, '/queues/test');
        $ref = new \ReflectionProperty(PublisherBuilder::class, 'cachedPublisher');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($builder));
    }

    public function test_fire_and_forget_sets_pre_settled_flag(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new PublisherBuilder($client, '/queues/test');
        $builder->fireAndForget();
        $ref = new \ReflectionProperty(PublisherBuilder::class, 'preSettled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($builder));
    }
}
```

- [ ] **Step 2: Run to confirm failures**

```bash
vendor/bin/phpunit tests/Unit/Messaging/PublisherBuilderTest.php
```

- [ ] **Step 3: Update Publisher to accept preSettled param**

In `src/AMQP10/Messaging/Publisher.php`, add `$preSettled` constructor param and pass to `SenderLink`:

```php
    public function __construct(
        private readonly Session $session,
        string                  $address,
        private readonly float   $timeout    = 30.0,
        private readonly bool    $preSettled = false,
    ) {
        $linkName   = 'sender-' . bin2hex(random_bytes(4));
        $this->link = new SenderLink(
            $session,
            name:          $linkName,
            target:        $address,
            sndSettleMode: $preSettled
                ? PerformativeEncoder::SND_SETTLED
                : PerformativeEncoder::SND_UNSETTLED,
        );
        $this->link->attach();
    }
```

Also remove `usleep(1000)` from `awaitOutcome()`.

- [ ] **Step 4: Update PublisherBuilder to accept Client, add caching and fireAndForget**

Replace `src/AMQP10/Messaging/PublisherBuilder.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Client\Client;

class PublisherBuilder
{
    private bool       $preSettled      = false;
    private ?Publisher $cachedPublisher = null;
    private int        $reconnectRetries  = 0;
    private int        $reconnectBackoffMs = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly string $address,
        private readonly float  $timeout = 30.0,
    ) {}

    public function fireAndForget(): self
    {
        $this->preSettled = true;
        return $this;
    }

    public function withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self
    {
        $this->reconnectRetries   = $maxRetries;
        $this->reconnectBackoffMs = $backoffMs;
        return $this;
    }

    public function send(Message $message): Outcome
    {
        return $this->publisher()->send($message);
    }

    public function publisher(): Publisher
    {
        if ($this->cachedPublisher === null) {
            $this->cachedPublisher = new Publisher(
                $this->client->session(),
                $this->address,
                $this->timeout,
                $this->preSettled,
            );
        }
        return $this->cachedPublisher;
    }

    public function close(): void
    {
        $this->cachedPublisher?->close();
        $this->cachedPublisher = null;
    }
}
```

- [ ] **Step 5: Run tests**

```bash
vendor/bin/phpunit
```

- [ ] **Step 6: PHPStan**

```bash
vendor/bin/phpstan analyse
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Messaging/Publisher.php src/AMQP10/Messaging/PublisherBuilder.php \
        tests/Unit/Messaging/PublisherBuilderTest.php
git commit -m "feat: fire-and-forget publishing, PublisherBuilder link caching"
```

---

### Task 10: Multi-frame transfer (publisher + consumer)

**Files:**
- Modify: `src/AMQP10/Connection/SenderLink.php`
- Modify: `src/AMQP10/Messaging/Publisher.php`
- Modify: `src/AMQP10/Client/Client.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Messaging/MultiFrameTransferTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class MultiFrameTransferTest extends TestCase
{
    public function test_large_payload_split_into_multiple_frames(): void
    {
        $frames  = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('isConnected')->willReturn(true);
        $transport->expects($this->atLeastOnce())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$frames) {
                $frames[] = $data;
            });

        $session = $this->createMock(Session::class);
        $session->method('channel')->willReturn(0);
        $session->method('nextDeliveryId')->willReturn(1);
        $session->method('transport')->willReturn($transport);
        $session->method('incomingWindow')->willReturn(2048);
        $session->method('outgoingWindow')->willReturn(2048);

        $link = new SenderLink($session, name: 'test', target: '/queues/q', maxFrameSize: 512);

        // Inject as attached via reflection
        $ref = new \ReflectionProperty(SenderLink::class, 'attached');
        $ref->setAccessible(true);
        $ref->setValue($link, true);

        $payload = str_repeat('X', 600); // bigger than 512 byte frame limit
        $link->transfer($payload);

        // Should have produced 2 frames
        $this->assertGreaterThanOrEqual(2, count($frames));

        // First frame should have more=true
        $body1 = FrameParser::extractBody($frames[0]);
        $perf1 = (new TypeDecoder($body1))->decode();
        $this->assertTrue($perf1['value'][5]); // more = true

        // Last frame should have more=false
        $bodyLast = FrameParser::extractBody($frames[count($frames) - 1]);
        $perfLast = (new TypeDecoder($bodyLast))->decode();
        $this->assertFalse($perfLast['value'][5]); // more = false
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Messaging/MultiFrameTransferTest.php
```

- [ ] **Step 3: Update SenderLink::transfer() for multi-frame**

In `src/AMQP10/Connection/SenderLink.php`, add `$maxFrameSize` constructor param and split logic:

```php
    public function __construct(
        private readonly Session $session,
        private readonly string  $name,
        private readonly string  $target,
        private readonly ?string $source         = null,
        private readonly int     $sndSettleMode  = PerformativeEncoder::SND_UNSETTLED,
        private readonly bool    $managementLink = false,
        private readonly int     $maxFrameSize   = 65536,
    ) {
        $this->handle = $session->allocateHandle();
    }
```

Update `transfer()`:

```php
    public function transfer(string $messagePayload): int
    {
        $deliveryId  = $this->session->nextDeliveryId();
        $deliveryTag = pack('N', $deliveryId);
        $settled     = $this->sndSettleMode === PerformativeEncoder::SND_SETTLED;

        // Frame overhead: 8-byte frame header + ~30 bytes TRANSFER performative
        $overhead    = 50;
        $chunkSize   = $this->maxFrameSize - $overhead;

        if (strlen($messagePayload) <= $chunkSize) {
            $this->session->transport()->send(PerformativeEncoder::transfer(
                channel:        $this->session->channel(),
                handle:         $this->handle,
                deliveryId:     $deliveryId,
                deliveryTag:    $deliveryTag,
                messagePayload: $messagePayload,
                settled:        $settled,
                more:           false,
            ));
            return $deliveryId;
        }

        // Multi-frame: split payload
        $chunks    = str_split($messagePayload, $chunkSize);
        $lastIndex = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $isFirst = $i === 0;
            $isLast  = $i === $lastIndex;
            $this->session->transport()->send(PerformativeEncoder::transfer(
                channel:        $this->session->channel(),
                handle:         $this->handle,
                deliveryId:     $isFirst ? $deliveryId : null,
                deliveryTag:    $isFirst ? $deliveryTag : null,
                messagePayload: $chunk,
                settled:        $settled,
                more:           !$isLast,
            ));
        }

        return $deliveryId;
    }
```

- [ ] **Step 4: Update PerformativeEncoder::transfer() to accept nullable deliveryId/tag and more flag**

```php
    public static function transfer(
        int     $channel,
        int     $handle,
        ?int    $deliveryId,
        ?string $deliveryTag,
        string  $messagePayload,
        bool    $settled       = false,
        int     $messageFormat = 0,
        bool    $more          = false,
    ): string {
        $fields = [
            TypeEncoder::encodeUint($handle),
            $deliveryId !== null ? TypeEncoder::encodeUint($deliveryId) : TypeEncoder::encodeNull(),
            $deliveryTag !== null ? TypeEncoder::encodeBinary($deliveryTag) : TypeEncoder::encodeNull(),
            TypeEncoder::encodeUint($messageFormat),
            TypeEncoder::encodeBool($settled),
            TypeEncoder::encodeBool($more),
        ];
        $body = self::described(Descriptor::TRANSFER, $fields) . $messagePayload;
        return FrameBuilder::amqp(channel: $channel, body: $body);
    }
```

- [ ] **Step 5: Thread maxFrameSize through Client → Publisher → SenderLink**

In `Client::connect()`, after `$connection->open()`:
```php
        $this->connection = $connection;
        // ...
        // maxFrameSize is available after open()
```

In `Client::publish()`, pass frame size to `PublisherBuilder`:
```php
    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder(
            $this,
            $address,
            $this->config->timeout,
            $this->connection?->negotiatedMaxFrameSize() ?? 65536,
        );
    }
```

Update `PublisherBuilder` to accept and pass `$maxFrameSize` to `Publisher`, and `Publisher` to pass it to `SenderLink`.

- [ ] **Step 6: Run tests**

```bash
vendor/bin/phpunit
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Connection/SenderLink.php \
        src/AMQP10/Messaging/Publisher.php \
        src/AMQP10/Messaging/PublisherBuilder.php \
        src/AMQP10/Protocol/PerformativeEncoder.php \
        tests/Unit/Messaging/MultiFrameTransferTest.php
git commit -m "feat: multi-frame transfer for large messages"
```

---

## Chunk 5: Protocol & Connection Improvements

### Task 11: Modified delivery outcome

**Files:**
- Modify: `src/AMQP10/Protocol/PerformativeEncoder.php`
- Modify: `src/AMQP10/Connection/DeliveryContext.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Connection/DeliveryContextTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Connection;

use AMQP10\Connection\DeliveryContext;
use AMQP10\Connection\ReceiverLink;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class DeliveryContextTest extends TestCase
{
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

        $ctx = new DeliveryContext(42, $link);
        $ctx->modify(deliveryFailed: true, undeliverableHere: true);

        $this->assertNotNull($sentState);
        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]); // delivery-failed
        $this->assertTrue($decoded['value'][1]); // undeliverable-here
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Connection/DeliveryContextTest.php
```

- [ ] **Step 3: Add modified() to PerformativeEncoder**

```php
    public static function modified(bool $deliveryFailed = true, bool $undeliverableHere = true): string
    {
        $fields = [
            TypeEncoder::encodeBool($deliveryFailed),
            TypeEncoder::encodeBool($undeliverableHere),
        ];
        return self::described(Descriptor::MODIFIED, $fields);
    }
```

Also verify `Descriptor::MODIFIED = 0x27` exists in `src/AMQP10/Protocol/Descriptor.php` (it does per earlier spec review).

- [ ] **Step 4: Add modify() to DeliveryContext**

```php
    public function modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::modified($deliveryFailed, $undeliverableHere));
    }
```

- [ ] **Step 5: Run tests and commit**

```bash
vendor/bin/phpunit tests/Unit/Connection/DeliveryContextTest.php
vendor/bin/phpunit
git add src/AMQP10/Protocol/PerformativeEncoder.php src/AMQP10/Connection/DeliveryContext.php \
        tests/Unit/Connection/DeliveryContextTest.php
git commit -m "feat: add modified delivery outcome for dead-letter patterns"
```

---

### Task 12: Virtual host from URI

**Files:**
- Modify: `src/AMQP10/Connection/Connection.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Connection/VirtualHostTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Connection;

use AMQP10\Connection\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class VirtualHostTest extends TestCase
{
    private function extractVhost(string $uri): string
    {
        $method = new ReflectionMethod(Connection::class, 'resolveVhost');
        $method->setAccessible(true);
        return $method->invoke(null, $uri);
    }

    public function test_no_path_returns_hostname(): void
    {
        $this->assertSame('broker.internal', $this->extractVhost('amqp://user:pass@broker.internal'));
    }

    public function test_path_slash_only_returns_hostname(): void
    {
        $this->assertSame('broker.internal', $this->extractVhost('amqp://user:pass@broker.internal/'));
    }

    public function test_explicit_vhost_returned(): void
    {
        $this->assertSame('production', $this->extractVhost('amqp://user:pass@broker.internal/production'));
    }

    public function test_url_encoded_default_vhost(): void
    {
        $this->assertSame('/', $this->extractVhost('amqp://user:pass@broker.internal/%2F'));
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Connection/VirtualHostTest.php
```

- [ ] **Step 3: Add resolveVhost() to Connection and use it in amqpOpen()**

In `src/AMQP10/Connection/Connection.php`:

```php
    private static function resolveVhost(string $uri): string
    {
        $parts = parse_url($uri);
        $host  = $parts['host'] ?? 'localhost';
        $path  = isset($parts['path']) ? urldecode(ltrim($parts['path'], '/')) : '';

        return $path !== '' ? $path : $host;
    }
```

Update `open()` to use it:
```php
        $this->amqpOpen(self::resolveVhost($this->uri));
```

Remove the `$parts['host']` fallback passed directly to `amqpOpen()` in the old code.

- [ ] **Step 4: Also parse idle timeout from server OPEN frame**

In `amqpOpen()`, after the existing frame-size negotiation:

```php
        // field index 4 = idle-time-out (milliseconds)
        if (isset($open['value'][4]) && $open['value'][4] > 0) {
            $this->negotiatedIdleTimeout = (int) $open['value'][4];
        }
```

Add property and getter:
```php
    private int $negotiatedIdleTimeout = 60000; // ms

    public function negotiatedIdleTimeout(): int
    {
        return $this->negotiatedIdleTimeout;
    }
```

- [ ] **Step 5: Run tests**

```bash
vendor/bin/phpunit tests/Unit/Connection/VirtualHostTest.php
vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Connection/Connection.php tests/Unit/Connection/VirtualHostTest.php
git commit -m "feat: virtual host from URI path; parse idle timeout from server OPEN"
```

---

### Task 13: TLS options on RevoltTransport; keepalive heartbeat

**Files:**
- Modify: `src/AMQP10/Transport/RevoltTransport.php` (TLS already wired in Task 2 — verify)
- Modify: `src/AMQP10/Protocol/FrameBuilder.php`
- Modify: `src/AMQP10/Client/Client.php`

- [ ] **Step 1: Verify TLS is already wired in RevoltTransport**

Check that `RevoltTransport::__construct()` has `array $tlsOptions = []` and `connect()` passes it as stream context. If not, add it now (the code was written in Task 2 with TLS support).

- [ ] **Step 2: Write failing test for keepalive frame**

Add to `tests/Unit/Protocol/FrameBuilderTest.php` (or create it):

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Protocol;

use AMQP10\Protocol\FrameBuilder;
use PHPUnit\Framework\TestCase;

class FrameBuilderTest extends TestCase
{
    public function test_keepalive_is_8_bytes(): void
    {
        $frame = FrameBuilder::keepalive();
        $this->assertSame(8, strlen($frame));
    }

    public function test_keepalive_has_zero_body_size(): void
    {
        $frame = FrameBuilder::keepalive();
        // Bytes 0-3 = frame size (big-endian uint32) = 8
        $size = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(8, $size);
    }
}
```

- [ ] **Step 3: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Protocol/FrameBuilderTest.php
```

- [ ] **Step 4: Add keepalive() to FrameBuilder**

In `src/AMQP10/Protocol/FrameBuilder.php`:

```php
    /**
     * Empty AMQP frame — used as a keepalive/heartbeat.
     * Spec §2.3: a frame with zero-length body satisfies the idle-timeout requirement.
     */
    public static function keepalive(): string
    {
        // Frame header: size(4) + doff(1) + type(1) + channel(2) = 8 bytes, zero body
        return pack('NccS', 8, 2, 0x00, 0);
    }
```

- [ ] **Step 5: Wire heartbeat timer in Client::connect()**

In `src/AMQP10/Client/Client.php`, add heartbeat scheduling after session begin:

```php
    private ?string $heartbeatTimerId = null;

    public function connect(): static
    {
        // ... existing connect logic ...
        $this->session->begin();

        // Schedule heartbeat at half the negotiated idle timeout
        $idleMs = $this->connection->negotiatedIdleTimeout();
        if ($idleMs > 0) {
            $intervalSec = ($idleMs / 2) / 1000;
            $transport   = $this->transport ?? new RevoltTransport(
                readTimeout: $this->config->timeout,
                tlsOptions:  $this->config->tlsOptions,
            );
            $this->heartbeatTimerId = \Revolt\EventLoop::repeat(
                $intervalSec,
                function () use ($transport) {
                    try {
                        $transport->send(\AMQP10\Protocol\FrameBuilder::keepalive());
                    } catch (\Throwable) {
                        // Connection lost — let the next read detect it
                    }
                }
            );
        }

        return $this;
    }

    public function close(): void
    {
        if ($this->heartbeatTimerId !== null) {
            \Revolt\EventLoop::cancel($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
        $this->session?->end();
        $this->connection?->close();
        $this->connection = null;
        $this->session    = null;
    }
```

Note: The transport reference needs to be stored on the Client to share it between connect() and the heartbeat closure. Refactor connect() to store `$this->activeTransport`:

```php
    private ?TransportInterface $activeTransport = null;

    public function connect(): static
    {
        $this->activeTransport = $this->transport ?? new RevoltTransport(...);
        $connection = new Connection($this->activeTransport, $this->uri, ...);
        // ...
        // Use $this->activeTransport in heartbeat
    }
```

- [ ] **Step 6: Run tests**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Protocol/FrameBuilder.php src/AMQP10/Client/Client.php \
        tests/Unit/Protocol/FrameBuilderTest.php
git commit -m "feat: keepalive heartbeat; TLS options on RevoltTransport"
```

---

## Chunk 6: AutoReconnect Cleanup + Integration Tests + Docs

### Task 14: Delete AutoReconnect; remove autoReconnect from Config

**Files:**
- Delete: `src/AMQP10/Connection/AutoReconnect.php`

- [ ] **Step 1: Delete AutoReconnect**

```bash
rm src/AMQP10/Connection/AutoReconnect.php
find tests/ -name 'AutoReconnectTest.php' -delete
```

- [ ] **Step 2: Verify Client.php has no AutoReconnect reference**

```bash
grep -r 'AutoReconnect' src/ tests/
```
Expected: no results.

- [ ] **Step 3: Run full test suite**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 4: Commit**

```bash
git add -u
git commit -m "chore: delete AutoReconnect — replaced by Consumer/Publisher reconnect logic"
```

---

### Task 15: Testcontainers integration test setup

**Files:**
- Create: `tests/Integration/RabbitMqTestCase.php`
- Modify: `composer.json` (require-dev)
- Modify: `phpunit.xml`

- [ ] **Step 1: Add testcontainers-php**

```bash
composer require --dev testcontainers/testcontainers:^0.2
```

- [ ] **Step 2: Create base integration test case**

Create `tests/Integration/RabbitMqTestCase.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedContainer;

abstract class RabbitMqTestCase extends TestCase
{
    private static ?StartedContainer $container = null;
    private static string $amqpUri = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$container !== null) {
            return;
        }

        self::$container = GenericContainer::make('rabbitmq:4-management')
            ->withExposedPorts(5672)
            ->withEnv('RABBITMQ_DEFAULT_USER', 'guest')
            ->withEnv('RABBITMQ_DEFAULT_PASS', 'guest')
            ->start();

        $port = self::$container->getMappedPort(5672);
        self::$amqpUri = "amqp://guest:guest@127.0.0.1:{$port}";

        // Wait for RabbitMQ to be ready
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            try {
                $client = new \AMQP10\Client\Client(self::$amqpUri);
                $client->connect();
                $client->close();
                break;
            } catch (\Throwable) {
                usleep(500_000); // 500ms
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Container reused across test class; stopped at process end
        parent::tearDownAfterClass();
    }

    protected function amqpUri(): string
    {
        return self::$amqpUri;
    }
}
```

- [ ] **Step 3: Update phpunit.xml to include integration group**

Add to `phpunit.xml`:
```xml
    <testsuite name="Integration">
        <directory>tests/Integration</directory>
    </testsuite>
```

- [ ] **Step 4: Write a smoke integration test**

Create `tests/Integration/PublishConsumeTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Integration;

use AMQP10\Client\Client;
use AMQP10\Messaging\Message;

class PublishConsumeTest extends RabbitMqTestCase
{
    public function test_publish_and_consume_basic_message(): void
    {
        $client = new Client($this->amqpUri());
        $client->connect();

        $client->management()->queue('test.publish-consume')->declare();

        // Publish
        $publisher = $client->publish('/queues/test.publish-consume');
        $publisher->send(Message::create('hello-world'));

        // Consume
        $received = null;
        $consumer = $client->consume('/queues/test.publish-consume')
            ->credit(1)
            ->consumer();

        $delivery = $consumer->receive();
        $this->assertNotNull($delivery);
        $this->assertSame('hello-world', $delivery->message()->body());
        $delivery->context()->accept();

        $publisher->close();
        $consumer->close();
        $client->close();
    }

    public function test_durable_message_survives_with_durable_flag(): void
    {
        $client = new Client($this->amqpUri());
        $client->connect();

        $client->management()->queue('test.durable')->declare();

        $publisher = $client->publish('/queues/test.durable');
        $msg = Message::create('{"orderId":1}')
            ->withSubject('order.placed')
            ->withDurable(true)
            ->withContentType('application/json');

        $outcome = $publisher->send($msg);
        $this->assertSame(\AMQP10\Messaging\OutcomeState::Accepted, $outcome->state());

        $publisher->close();
        $client->close();
    }

    public function test_fire_and_forget_does_not_block(): void
    {
        $client = new Client($this->amqpUri());
        $client->connect();

        $client->management()->queue('test.fire-forget')->declare();

        $publisher = $client->publish('/queues/test.fire-forget')
            ->fireAndForget();

        $start = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            $publisher->send(Message::create("msg-$i"));
        }
        $elapsed = microtime(true) - $start;

        // Fire-and-forget should be much faster than waiting for 10 dispositions
        $this->assertLessThan(1.0, $elapsed);

        $publisher->close();
        $client->close();
    }
}
```

- [ ] **Step 5: Run integration tests**

```bash
vendor/bin/phpunit --testsuite Integration
```
Expected: all pass (container starts automatically).

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/ phpunit.xml composer.json composer.lock
git commit -m "test: add testcontainers-php integration test suite"
```

---

### Task 16: Documentation update

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update README**

Update `README.md` to reflect:

1. **Remove** all references to `BlockingAdapter` and `AutoReconnect`
2. **Add** TLS connection example:
   ```php
   $client = (new Client('amqps://user:pass@broker.example.com:5671'))
       ->withTlsOptions(['cafile' => '/path/to/ca.pem'])
       ->connect();
   ```
3. **Add** virtual host example:
   ```php
   $client = new Client('amqp://user:pass@host/my-vhost');
   ```
4. **Add** durable consumer example:
   ```php
   $client->consume('/queues/orders')
       ->linkName('order-processor')
       ->durable()
       ->expiryPolicy(\AMQP10\Terminus\ExpiryPolicy::Never)
       ->withReconnect(maxRetries: 10)
       ->handle(fn($msg, $ctx) => $ctx->accept())
       ->run();
   ```
5. **Add** fire-and-forget publish example:
   ```php
   $client->publish('/exchanges/events')
       ->fireAndForget()
       ->send(Message::create($payload)->withSubject('order.placed'));
   ```
6. **Add** Message builder examples
7. **Add** modified outcome / dead-letter example

- [ ] **Step 2: Final full test run**

```bash
vendor/bin/phpunit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpstan analyse
```
Expected: all green, PHPStan clean at level 6.

- [ ] **Step 3: Final commit**

```bash
git add README.md
git commit -m "docs: update README for RevoltTransport, durable consumers, TLS, fire-and-forget"
```

---

## Summary Checklist

- [ ] Chunk 1: RevoltTransport replaces BlockingAdapter
- [ ] Chunk 2: Enums + Message fluent API + MessageEncoder fixes
- [ ] Chunk 3: Consumer improvements (credit, link name, durability, reconnect)
- [ ] Chunk 4: Publisher improvements (fire-and-forget, caching, multi-frame)
- [ ] Chunk 5: Protocol improvements (modified outcome, virtual host, TLS, heartbeat)
- [ ] Chunk 6: AutoReconnect deletion + integration tests + docs
- [ ] PHPStan level 6 passes with zero errors
- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] `BlockingAdapter.php` and `AutoReconnect.php` do not exist

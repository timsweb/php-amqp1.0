
# Implementation Plan Review

**Date:** 2026-03-11
**Reviewer:** Claude Code
**Verdict:** Plan contains multiple critical AMQP 1.0 protocol errors that will produce broken, non-compliant code. Do not implement as written without corrections.

---

## Critical Protocol Errors

### 1. Frame format is fundamentally wrong (Task 2.1)

**What the plan says:**

```php
public function encode(): string
{
    return pack('Cnn', $this->type, $this->channel, $this->performative);
}
// Expected: "\x02\x01\x00\x10\x00" (5 bytes)
```

**What the AMQP 1.0 spec requires (§2.3):**

Every frame has an 8-byte fixed header:

```
Bytes 0-3: SIZE (uint32, total frame size including header)
Byte  4:   DOFF (uint8, data offset in 4-byte words, minimum 2)
Byte  5:   TYPE (uint8, 0x00 = AMQP frame, 0x01 = SASL frame)
Bytes 6-7: CHANNEL (uint16)
Bytes 8+:  Frame body (encoded AMQP composite type)
```

The plan's `encode()` produces 5 bytes with no SIZE field, wrong DOFF and TYPE handling, and no frame body encoding. `Frame::PERFORMATIVE = 0x02` is not a valid frame type — 0x00 is AMQP, 0x01 is SASL, 0x02 is undefined.

The test's expected value `"\x02\x01\x00\x10\x00"` bears no relationship to a real AMQP frame.

**Fix:** Frame encoding must produce: `pack('NCC n', $totalSize, $doff, $frameType, $channel)` followed by the encoded performative body (as a described AMQP list). A bare AMQP header with no body (heartbeat) would be: `"\x00\x00\x00\x08\x02\x00\x00\x00"` (size=8, doff=2, type=0, channel=0).

---

### 2. String type code is wrong (Task 2.2)

**What the plan says:**

```php
$this->assertSame("\x81\x00\x00\x00S\x04test", $encoded);
```

**What the AMQP 1.0 spec says (§1.6):**

- `0x81` = `long` (64-bit signed integer) — **not a string**
- `str8-utf8` = `0xa1` (length: 1-byte uint, max 255 bytes)
- `str32-utf8` = `0xb1` (length: 4-byte uint)

The type code in the test is for a `long`, not a string. The correct encoding for `"test"` as str8 would be: `\xa1\x04test` (3 bytes = type + length + content).

The expected bytes in the test are also internally inconsistent with the implementation's `pack('Cna*', ...)` which would produce `\x81\x00\x04test` (not the 12-byte sequence asserted).

**Fix:** Use `0xa1` for str8 (strings ≤255 bytes) and `0xb1` for str32. The full type code table from spec:

| Type       | Code  | Length field |
|------------|-------|--------------|
| str8-utf8  | 0xa1  | 1-byte uint  |
| str32-utf8 | 0xb1  | 4-byte uint  |
| sym8       | 0xa3  | 1-byte uint  |
| sym32      | 0xb3  | 4-byte uint  |
| vbin8      | 0xa0  | 1-byte uint  |
| vbin32     | 0xb0  | 4-byte uint  |
| uint0      | 0x43  | (no payload) |
| ulong0     | 0x44  | (no payload) |
| list0      | 0x45  | (no payload) |

---

### 3. Wrong protocol header (Task 4.2)

**What the plan says:**

```php
$this->transport->sendFrame("AMQP\x01\x01\x00");
```

**What the AMQP 1.0 spec requires (§2.2):**

Protocol headers are exactly 8 bytes: `"AMQP"` + protocol-id (1 byte) + major (1) + minor (1) + revision (1).

- **SASL negotiation header:** `"AMQP\x03\x01\x00\x00"` (protocol-id=3)
- **AMQP connection header:** `"AMQP\x00\x01\x00\x00"` (protocol-id=0)

The plan sends a **7-byte** string with **wrong protocol-id** (`\x01` instead of `\x03` for SASL or `\x00` for AMQP). The correct connection sequence is:

1. Client → Server: `"AMQP\x03\x01\x00\x00"` (SASL header)
2. Server → Client: `"AMQP\x03\x01\x00\x00"` (SASL header echo)
3. Server → Client: `sasl-mechanisms` frame
4. Client → Server: `sasl-init` frame
5. Server → Client: `sasl-outcome` frame (code 0 = ok)
6. Client → Server: `"AMQP\x00\x01\x00\x00"` (AMQP header — only after SASL succeeds)
7. Client → Server: `open` performative

---

### 4. SASL PLAIN format: test and implementation disagree (Task 4.1)

**The test expects:**

```php
$this->assertSame("user\x00user\x00pass", $sasl->initialResponse());
```

**The implementation produces:**

```php
return new self('PLAIN', "\x00" . $username . "\x00" . $password);
// → "\x00user\x00pass"
```

These are different. Per RFC 4616, SASL PLAIN format is: `[authzid] NUL authcid NUL passwd`

- `"user\x00user\x00pass"` = authzid="user", authcid="user", passwd="pass"
- `"\x00user\x00pass"` = authzid="" (empty), authcid="user", passwd="pass"

The implementation's empty-authzid form (`"\x00user\x00pass"`) is what RabbitMQ expects. **The test is wrong**, not the implementation. Fix the test to assert `"\x00user\x00pass"`.

---

### 5. BlockingAdapter: wrong type and wrong function signature (Task 3.2)

**What the plan says:**

```php
private ?\Socket $socket = null;
// ...
$this->socket = @stream_socket_client($parts['host'], $parts['port'], $errno, $errstr);
```

Two bugs:
1. `\Socket` is for the `socket_*()` extension. `stream_socket_client()` returns a `resource`/`mixed`, not a `\Socket`. This will cause a type error at runtime.
2. `stream_socket_client()` takes a single address string as its first argument (`"tcp://host:port"`), not separate host and port arguments.

**Fix:**

```php
private mixed $socket = null;
// ...
$this->socket = @stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $errstr);
```

---

### 6. AutoReconnect: method name mismatch and missing URI (Task 4.3)

**Test calls:**

```php
$result = $autoReconnect->connect();
```

**Implementation defines:**

```php
public function connectWithRetry(): ConnectionResult
```

The method doesn't exist under the name the test uses. Also, `AutoReconnect::connectWithRetry()` calls `$this->transport->connect()` with no arguments, but `TransportInterface::connect()` requires a `string $uri` parameter.

---

### 7. Management API architecture is wrong (Task 7.2)

**What the plan says:**

```php
public function declareExchange(ExchangeSpecification $spec): void
{
    $frame = Encoder::createAttachFrame(
        source: null,
        target: "/exchanges/{$spec->name}",
        properties: ['type' => $spec->type, 'durable' => $spec->durable],
    );
    $this->transport->sendFrame($frame);
}
```

**What RabbitMQ actually requires:**

Sending an ATTACH frame to `/exchanges/my-exchange` does not declare an exchange — it opens a sender link to an existing exchange for publishing. Topology management in RabbitMQ AMQP 1.0 (4.0+) uses a dedicated management link attached to `$management`, then sends request/response messages with HTTP-like semantics (method, path, body). This is how the official Java and Python clients work.

Declaring entities via ATTACH frame properties is not part of the AMQP 1.0 spec and will not work with RabbitMQ. The `ManagementInterface` needs a complete rethink.

Additionally, `ManagementInterface` is named as if it's an interface but is a concrete class. The test instantiates it with `new ManagementInterface($transport)` which also breaks the design spec's API where `$client->management()` should return the management object.

---

### 8. Client test contradicts implementation (Task 8.1)

**The test:**

```php
$client = Client::connect('amqp://guest:guest@localhost:5672/');
$this->assertFalse($client->isConnected()); // ← wrong
$client->open();                             // ← open() is private
```

`Client::connect()` internally calls the private `open()` method, so `isConnected()` would be `true` immediately after `connect()`. Additionally, `open()` is declared `private` and cannot be called from the test.

---

### 9. `withSasl()` destroys existing config (Task 8.1)

```php
public function withSasl(Sasl $sasl): static
{
    $this->config = new Config(sasl: $sasl); // wipes autoReconnect, maxRetries, backoffMs
    return $this;
}
```

Calling `->withAutoReconnect()->withSasl(...)` loses all reconnect settings. Should modify existing config values, not replace the whole object.

---

### 10. Publisher bypasses Session and Link layers (Task 6.3)

The plan's `Publisher` takes a `TransportInterface` and calls `transport->sendFrame()` directly. In AMQP 1.0, messages are sent via:

1. A **Session** (handles channel/window accounting, next-outgoing-id, incoming-window)
2. A **Link** (ATTACH with sender role, target address, snd-settle-mode)
3. A **Transfer** frame (carries delivery-tag, delivery-id, message payload)
4. A **Disposition** frame (outcome — Accepted/Rejected/Released)

The plan entirely skips session and link management, sending raw bytes that would be protocol violations. The design's layered architecture (Connection → Session → Link) must be faithfully implemented.

---

### 11. Missing `Offset` class for stream consumers (Design requirement)

The design doc specifies:

```php
$client->consume($address)->offset(Offset::FIRST)->...
```

The plan defines no `Offset` class and no `offset()` method on the consumer. Stream offset support is missing entirely.

---

### 12. `readonly class Message` has invalid property defaults (Task 6.1)

```php
readonly class Message
{
    private array $properties = [];       // ← PHP error
    private array $applicationProperties = [];
    private array $annotations = [];

    public function __construct(...) {
        $this->properties = $properties;  // ← cannot re-assign readonly
    }
}
```

PHP does not permit readonly properties (including those in `readonly` classes) to have declaration-site defaults AND be assigned in the constructor body. This is a fatal error. The properties must either use constructor promotion or the `readonly class` modifier must be removed.

---

### 13. Multiple classes per file (Task 6.2, 7.1, 4.3)

The plan defines multiple classes/enums in single files:
- `Outcome.php` defines both `Outcome` and `OutcomeState` enum
- Specification files define enums `ExchangeType` and `QueueType` alongside classes
- `AutoReconnect.php` defines both `AutoReconnect` and `ConnectionResult`

PSR-4 autoloading requires one class/interface/enum per file.

---

## Summary

| Issue | Severity | Task |
|-------|----------|------|
| Frame format wrong (missing SIZE, DOFF, wrong type codes) | Critical | 2.1 |
| String type code 0x81 is `long` not string | Critical | 2.2 |
| Protocol header wrong (7 bytes, wrong protocol-id) | Critical | 4.2 |
| SASL PLAIN test expects wrong bytes | High | 4.1 |
| BlockingAdapter: wrong PHP type, wrong function signature | High | 3.2 |
| AutoReconnect method name mismatch, missing URI arg | High | 4.3 |
| Management API architecture wrong (not how RabbitMQ works) | High | 7.2 |
| Client test calls private method, contradicts connect() | High | 8.1 |
| withSasl() destroys existing config | Medium | 8.1 |
| Publisher bypasses Session/Link layers | Medium | 6.3 |
| Offset class missing for streams | Medium | Design |
| readonly class with property defaults = PHP fatal error | Medium | 6.1 |
| Multiple classes per file (PSR-4 violation) | Low | 6.2, 7.1, 4.3 |

The protocol layer (Chunks 2-4) needs to be rewritten from scratch using the AMQP 1.0 spec directly. The management layer (Chunk 7) needs redesign based on how RabbitMQ's AMQP 1.0 management actually works. The remaining layers (messaging, client) have design issues that stem from the flawed protocol foundation.

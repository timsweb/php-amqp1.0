# Messaging API Gaps

## Context

The library has gaps that prevent it being used for the core use case: a web app publishing events (order.placed, catalog.updated) consumed by durable backend processes. Issues range from a critical bug (credit exhaustion) through broken durability semantics to DX problems that will teach users bad patterns.

This spec covers all gaps identified during review. The Revolt/Fibers transport change is in a separate spec.

## Gaps and Fixes

---

### 1. Credit Replenishment (Critical Bug)

**Problem:** `ReceiverLink::grantCredit()` is only called once in `attach()`. After the initial credit is exhausted the broker stops delivering messages and the consumer hangs silently. Any long-running consumer breaks here.

**Fix:** `Consumer` must track how many messages have been received and replenish credit periodically. The standard approach is to replenish once half the credit window has been consumed (i.e. grant `ceil($credit / 2)` new credits after every `floor($credit / 2)` deliveries).

The `deliveryCount` argument to `grantCredit` is the receiver's echo of the sender's cumulative delivery-count. In practice, passing the consumer's own cumulative received count is a correct approximation for RabbitMQ — the broker computes remaining credit as `delivery-count + link-credit - last-seen-delivery-count`. Passing cumulative received count keeps this in sync.

**Changes:**
- `Consumer`: add `$received` counter; after every `floor($credit / 2)` deliveries call `$this->link->grantCredit(ceil($credit / 2), $this->received)`
- `ReceiverLink::grantCredit()`: already accepts `$deliveryCount` — no change needed at the link level

---

### 2. Stable Link Name for Durable Consumers

**Problem:** `Consumer` generates `'receiver-' . bin2hex(random_bytes(4))` as the link name on every instantiation. The broker associates subscription state (position, unacked messages) with the link name. A random name means every restart is a new consumer.

**Fix:** Add `ConsumerBuilder::linkName(string $name)` to allow callers to specify a stable, application-defined name.

**Changes:**
- `ConsumerBuilder`: add `linkName(string $name): self` setter
- `Consumer`: accept optional `?string $linkName` constructor parameter; fall back to random if null
- `ReceiverLink`: already accepts `$name` via constructor — no change needed

---

### 3. Terminus Durability and Expiry Policy

**Problem:** `PerformativeEncoder::encodeSource()` hardcodes `durable=null` and `expiry-policy=null`. A consumer whose subscription should survive disconnection cannot express this. Raw integer/string values are also error-prone; typed enums are clearer.

**AMQP 1.0 values:**
- `durable`: 0 = none, 1 = configuration, 2 = unsettled-state
- `expiry-policy`: `link-detach`, `session-end`, `connection-close`, `never`

For a typical durable consumer: `TerminusDurability::UnsettledState, ExpiryPolicy::Never`.

**New enums:**

```php
enum TerminusDurability: int {
    case None          = 0;
    case Configuration = 1;
    case UnsettledState = 2;
}

enum ExpiryPolicy: string {
    case LinkDetach      = 'link-detach';
    case SessionEnd      = 'session-end';
    case ConnectionClose = 'connection-close';
    case Never           = 'never';
}
```

**Fix:**
- `ConsumerBuilder::durable(TerminusDurability $durability = TerminusDurability::UnsettledState): self`
- `ConsumerBuilder::expiryPolicy(ExpiryPolicy $policy = ExpiryPolicy::Never): self`
- `Consumer`: pass these through to `ReceiverLink`
- `ReceiverLink`: accept `?TerminusDurability $durable` and `?ExpiryPolicy $expiryPolicy` constructor parameters, pass to `PerformativeEncoder::attach()`
- `PerformativeEncoder::attach()`: accept `?TerminusDurability $durable` and `?ExpiryPolicy $expiryPolicy` and pass to `encodeSource()`
- `PerformativeEncoder::encodeSource()`: accept these typed params; encode `$durable->value` and `$expiryPolicy->value`; always build the full field list when any of `$filterMap`, `$durable`, or `$expiryPolicy` is non-null. The short-path `$fields = [encodeString($address)]` must only be taken when all three are null.

Field positions in source terminus (AMQP 1.0 §3.5.3): index 0 = address, index 1 = durable, index 2 = expiry-policy, index 3 = timeout, index 4 = dynamic, index 5 = dynamic-node-properties, index 6 = distribution-mode, index 7 = filter, index 10 = capabilities.

---

### 4. Message Durable Flag

**Problem:** `MessageEncoder` hardcodes `durable=false` in the header section. Messages are lost on broker restart. For event-driven systems (order.placed, catalog.updated) this is almost always wrong.

**Fix:** Expose `durable` on `Message` with a default of `true`. There are no existing users of this library so API compatibility is not a concern.

**Changes:**
- `Message`: add `private readonly bool $durable = true` constructor parameter
- `MessageEncoder::encode()`: use `$message->durable()` instead of hardcoded `false`; emit header section whenever `$durable || $ttl > 0 || $priority !== 4`
- `Message::durable()`: new getter

---

### 5. Fire-and-Forget Publishing (Pre-Settled)

**Problem:** `PublisherBuilder` and `Publisher` always use `SND_UNSETTLED`, blocking on broker disposition. A web app publishing events doesn't need confirmation — it adds latency for no benefit.

**Fix:** `PublisherBuilder::fireAndForget(): self` sets `SND_SETTLED` mode on the underlying `SenderLink`.

**Changes:**
- `PublisherBuilder`: add `private bool $preSettled = false` and `fireAndForget(): self` setter; pass settle mode to `Publisher` constructor
- `Publisher`: accept `bool $preSettled = false`; pass `SND_SETTLED` or `SND_UNSETTLED` to `SenderLink`

---

### 6. Subject Property (Routing Key) Ergonomics

**Problem:** In RabbitMQ 4 AMQP 1.0, the `subject` property is the routing key. Publishing to `/exchanges/my-exchange` without a subject sends to the default binding. The current API requires `new Message($body, properties: ['subject' => 'order.placed'])` — the `subject` key is undiscoverable.

**Fix:** Expose `subject` as a first-class field on `Message`.

**Changes to `Message`:**
- Constructor parameter: `private readonly ?string $subject = null`
- Getter: `subject(): ?string`

**Changes to `MessageEncoder`:**
- The properties section must be emitted whenever any first-class property field is non-null, not only when `$properties` array is non-empty. Change the guard from `if (!empty($props))` to `if (!empty($props) || $message->subject() !== null)` (and extend as other first-class fields are added in §9).
- Read `subject` from `$message->subject()` first, falling back to `$props['subject']` for backwards compatibility.

**Preferred API for callers** (via wither methods added in §9):
```php
Message::create('{"orderId":123}')
    ->withSubject('order.placed')
    ->withApplicationProperty('source', 'checkout')
```

---

### 7. PublisherBuilder::send() Opens a New Link Per Message

**Problem:** `PublisherBuilder::send()` creates a `Publisher` (attaches a link), sends one message, then immediately calls `$publisher->close()` (detaches). For a web app sending one event per request this means an attach/detach round trip on every send. The ergonomic path leads directly to the inefficient pattern.

**Chosen fix:** `PublisherBuilder` lazily creates and caches a single `Publisher` on first use. Both `send()` and `publisher()` return/use this same cached instance. `PublisherBuilder::close()` is added to detach when done.

**Changes:**
- `PublisherBuilder`: add `private ?Publisher $cachedPublisher = null`
- `publisher()`: return `$this->cachedPublisher ??= new Publisher(...)` (caches on first call)
- `send()`: delegate to `$this->publisher()->send($message)` — no longer creates and closes inline
- Add `close(): void` that calls `$this->cachedPublisher?->close()` and nulls the cache

This makes `publisher()` and `send()` consistent: both use the same cached link. Callers who want explicit lifecycle control use `publisher()` then call `->close()` on the builder when done.

---

### 8. Heartbeat Sending

**Problem:** The OPEN frame negotiates `idleTimeOut=60000ms` but nothing sends keepalive frames. An idle connection (e.g. a web app between requests) is closed by the broker after 60 seconds. `AutoReconnect` only covers initial connect, not mid-session drops.

**Fix:** After `Connection::open()`, schedule a Revolt timer to send an empty AMQP frame every `idleTimeOut / 2` milliseconds. An empty AMQP frame (8-byte frame with zero-length body) satisfies the keepalive requirement.

**Prerequisite:** Requires Revolt transport (separate spec). Not feasible with the blocking transport — no background execution.

**Changes:**
- `FrameBuilder`: add `static function keepalive(): string` — 8-byte empty AMQP frame
- `Connection`: expose `negotiatedIdleTimeout(): int` (already stores `$negotiatedMaxFrameSize`; add `$negotiatedIdleTimeout` similarly, parsed from server's OPEN frame)
- `Client::connect()`: after session begin, if using `RevoltTransport`, schedule `EventLoop::repeat($idleTimeout / 2000, fn() => $transport->send(FrameBuilder::keepalive()))` and store the timer ID
- `Client::close()`: cancel the heartbeat timer

---

### 9. Message Fluent Builder

**Problem:** `new Message($body, properties: ['subject' => 'order.placed', 'message-id' => $id], applicationProperties: ['source' => 'checkout'])` is verbose and requires knowing internal key names.

**Fix:** Add wither methods to `Message` (returning new instances) and a static factory:

```php
$message = Message::create($body)
    ->withSubject('order.placed')
    ->withMessageId($orderId)
    ->withContentType('application/json')
    ->withApplicationProperty('source', 'checkout')
    ->withDurable(true);
```

**Changes to `Message`:**
- Add `static function create(string $body): self`
- Add wither methods: `withSubject()`, `withMessageId()`, `withCorrelationId()`, `withReplyTo()`, `withContentType()`, `withTtl()`, `withPriority()`, `withDurable()`, `withApplicationProperty(string $key, mixed $value)`, `withAnnotation(string $key, mixed $value)`
- Each wither clones `$this` with one field changed and returns the new instance
- `MessageEncoder` continues reading from the same getters — no structural change needed (§6 encoder guard update already handles first-class fields)

---

### 10. ReceiverLink Flow Window Synchronised with Session

**Problem:** `ReceiverLink::grantCredit()` hardcodes `incomingWindow: 2048, outgoingWindow: 2048` in the FLOW frame regardless of the Session's actual window configuration. If session windows are customised, flow control becomes inconsistent.

**Fix:** `ReceiverLink` reads window values from its `Session` reference (which already has `$incomingWindow` and `$outgoingWindow`).

**Changes:**
- `Session`: expose `incomingWindow(): int` and `outgoingWindow(): int` getters
- `ReceiverLink::grantCredit()`: replace hardcoded `2048` values with `$this->session->incomingWindow()` and `$this->session->outgoingWindow()`

---

### 11. TLS Context Options

**Problem:** `BlockingAdapter` (now deleted) detected `amqps://` but passed no stream context. `RevoltTransport` inherits the same gap — it switches to `ssl://` but has no way to configure CA certificate, client certificate, `verify_peer`, peer name, or any other TLS option. This blocks all production use with real broker certificates.

**Fix:** `RevoltTransport` accepts an optional `array $tlsOptions` constructor parameter passed as the `ssl` key of `stream_context_create()`.

Common options users will need:
- `verify_peer` / `verify_peer_name` — disable for self-signed certs in dev
- `cafile` — custom CA bundle
- `local_cert` / `local_pk` — mutual TLS (client certificate authentication)
- `peer_name` — override expected hostname

**Changes:**
- `RevoltTransport`: add `private readonly array $tlsOptions = []` constructor parameter; create a stream context with `stream_context_create(['ssl' => $this->tlsOptions])` and pass to `stream_socket_client()` when TLS is in use
- `Client`/`Config`: expose `withTlsOptions(array $options): static` so callers don't need to construct the transport manually

**Example API:**
```php
$client = (new Client('amqps://user:pass@broker.example.com:5671'))
    ->withTlsOptions(['cafile' => '/etc/ssl/certs/ca-bundle.pem']);
```

---

### 12. Modified Delivery Outcome

**Problem:** `DeliveryContext` provides `accept()`, `reject()`, and `release()` but not `modify()`. The AMQP `modified` outcome is essential for dead-letter patterns: it signals "do not redeliver to this consumer, but the message is not discarded — route it elsewhere or increment delivery count". Without it, the only dead-letter option is `reject()`, which permanently discards the message depending on broker config.

**AMQP modified fields:**
- `delivery-failed` (bool) — increment delivery count at destination
- `undeliverable-here` (bool) — do not redeliver to this link
- `message-annotations` (map) — merge into message annotations before redelivery

**Fix:** Add `modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void` to `DeliveryContext`.

**Changes:**
- `PerformativeEncoder`: add `static function modified(): string` encoding the modified outcome described type (descriptor `0x27`) with `delivery-failed` and `undeliverable-here` fields
- `DeliveryContext`: add `modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void` that calls `$this->link->settle($this->deliveryId, PerformativeEncoder::modified($deliveryFailed, $undeliverableHere))`

---

### 13. Large Message Support (Multi-Frame Transfer)

**Problem:** `PerformativeEncoder::transfer()` hardcodes `more=false` and sends the entire payload as a single TRANSFER frame. AMQP 1.0 frames have a negotiated maximum size (default 64KB). Any message payload exceeding `negotiatedMaxFrameSize - frame-overhead` (~64KB) will be silently truncated or cause a protocol error at the broker.

For the consumer side, `Consumer` calls `session->nextFrame()` and immediately decodes a delivery — it has no reassembly logic for multi-frame transfers (`more=true`).

**Fix:**

*Publisher side:* `Publisher` (or `SenderLink`) splits the message payload across multiple TRANSFER frames when it exceeds the negotiated frame size, setting `more=true` on all but the last. The `negotiatedMaxFrameSize` is already tracked on `Connection` and should be threaded through to `Publisher`/`SenderLink`.

*Consumer side:* `Consumer::receive()` accumulates raw payloads across consecutive TRANSFER frames with `more=true` on the same delivery (same `delivery-id` and `delivery-tag`), only decoding the full message once the final frame (`more=false`) arrives.

**Changes:**
- `Connection`: `negotiatedMaxFrameSize()` already exists as a public getter — no change needed here
- `Publisher`: accept `int $maxFrameSize` (from connection); pass to `SenderLink`
- `SenderLink::transfer()`: if `strlen($messagePayload) + frame-overhead > $maxFrameSize`, split into chunks and send multiple TRANSFER frames; only the first frame carries `delivery-id` and `delivery-tag`; all frames except the last carry `more=true` in their own transfer performative; the final frame carries `more=false`
- `Consumer::receive()`: track in-progress deliveries by delivery-id; accumulate payload bytes while `more=true`; decode only when a frame with `more=false` closes the delivery
- `Client::publish()`: thread `$connection->negotiatedMaxFrameSize()` through to `Publisher`

---

### 14. Virtual Host Addressing

**Problem:** `Connection::open()` parses `host`, `user`, `pass`, and `port` from the URI but ignores the path component. In RabbitMQ AMQP 1.0, the virtual host is selected via the `hostname` field in the OPEN performative. The URI path is the conventional way to express vhost (e.g. `amqp://user:pass@host/my-vhost`), but the current code always sends the bare hostname, silently connecting to the default vhost regardless of what the URI specifies.

**Fix:** Parse the URI path component and, if present and non-empty, use it as the virtual host. Send it as the `hostname` field in OPEN. RabbitMQ treats the `hostname` field as the vhost selector; the default vhost is `/`.

**Changes:**
- `Connection::open()`: extract `$parts['path']` from the URI; strip the leading `/` to get the vhost name; if non-empty pass as `hostname` to `amqpOpen()`, otherwise fall back to the actual hostname
- No changes to the public API — vhost is part of the URI

**Example:**
```php
// Connects to vhost "production"
$client = new Client('amqp://user:pass@broker.internal/production');

// Connects to default vhost
$client = new Client('amqp://user:pass@broker.internal');
```

---

## Files to Create

None — all changes are to existing files.

---

### 15. Runtime Reconnection (Replacing AutoReconnect)

**Problem:** The current `AutoReconnect` class only wraps `Connection::open()` with retries on initial startup. This is largely pointless — it handles transient network blips at connect time but does nothing when an established connection drops mid-operation. The real need is reconnection when a running consumer loses its connection due to broker maintenance, cluster failover, or network interruption.

**Replace `AutoReconnect` with connection-drop handling in `Consumer` and `Client`.**

**Architecture:**

`ConsumerBuilder` currently holds a `Session` reference, which is tied to a specific connection. After a reconnect, the old `Session` is dead. To support reconnection, `ConsumerBuilder` must hold a `Client` reference instead — so it can call `$client->reconnect()` and obtain a fresh `Session`.

`Consumer::run()` catches `ConnectionFailedException` and `\RuntimeException` (transport closed), calls the client to reconnect, re-creates its `ReceiverLink` using its stored configuration, and resumes the run loop.

**Changes:**

- `ConsumerBuilder`: accept `Client` instead of `Session` in constructor; store it; obtain session from `$client->session()` lazily on first `consumer()` call
- `Client`: add `reconnect(): void` — tears down current session/connection and rebuilds; add `session(): Session` internal getter (already exists as private, promote to package-accessible or refactor)
- `Consumer`: store all configuration needed to re-attach (`$address`, `$credit`, `$linkName`, `$durable`, `$expiryPolicy`, filters); add `reattach(Session $session): void` that creates a new `ReceiverLink` and calls `attach()`
- `Consumer::run()`: wrap the receive loop in a try/catch; on connection failure, call `$client->reconnect()` with exponential backoff (configurable via `ConsumerBuilder`), then call `$this->reattach($client->session())`
- `AutoReconnect.php`: **deleted** — its usleep-based backoff is replaced by the Revolt-friendly reconnect path in `Consumer`
- `Config`: remove `autoReconnect`, `maxRetries`, `backoffMs` fields (replaced by `ConsumerBuilder::withReconnect()`)
- `ConsumerBuilder`: add `withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self`

**Example API:**
```php
$client->consume('/queues/orders')
    ->linkName('order-processor')
    ->durable()
    ->withReconnect(maxRetries: 10, backoffMs: 2000)
    ->handle(function(Message $message, DeliveryContext $ctx) {
        // process...
        $ctx->accept();
    })
    ->run();
```

**Publisher reconnection:** `Publisher` and `PublisherBuilder` follow the same pattern — catch connection failure on `send()`, reconnect via `Client`, re-attach the sender link. This is lower priority than consumer reconnection; add in the same implementation pass.

**Note:** The `AutoReconnect` usleep backoff is replaced by `EventLoop::delay()` from Revolt, making the reconnect wait non-blocking (the Fiber suspends during backoff rather than blocking the thread).

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/AMQP10/Terminus/TerminusDurability.php` | Enum: None, Configuration, UnsettledState |
| `src/AMQP10/Terminus/ExpiryPolicy.php` | Enum: LinkDetach, SessionEnd, ConnectionClose, Never |

## Files to Delete

| File | Reason |
|------|--------|
| `src/AMQP10/Connection/AutoReconnect.php` | Replaced by runtime reconnection in Consumer/Publisher |

## Files to Modify

| File | Changes |
|------|---------|
| `src/AMQP10/Messaging/Consumer.php` | Credit replenishment; `$linkName`, `$durable`, `$expiryPolicy` params; multi-frame reassembly; reconnect/reattach logic |
| `src/AMQP10/Messaging/ConsumerBuilder.php` | Accept `Client`; `linkName()`, `durable()`, `expiryPolicy()`, `withReconnect()` |
| `src/AMQP10/Connection/ReceiverLink.php` | `$durable`, `$expiryPolicy` params; sync flow window from session |
| `src/AMQP10/Connection/Session.php` | Add `incomingWindow()` and `outgoingWindow()` getters |
| `src/AMQP10/Protocol/PerformativeEncoder.php` | `attach()` + `encodeSource()` for durable/expiry-policy; add `modified()` |
| `src/AMQP10/Messaging/Message.php` | `$durable`, `$subject` fields; `create()` factory; wither methods |
| `src/AMQP10/Messaging/MessageEncoder.php` | Use `$message->durable()`; update properties section guard; read `subject()` |
| `src/AMQP10/Messaging/Publisher.php` | `$preSettled`, `$maxFrameSize` params; multi-frame splitting; reconnect logic |
| `src/AMQP10/Messaging/PublisherBuilder.php` | Accept `Client`; `fireAndForget()`; cached publisher; `close()`; `withReconnect()` |
| `src/AMQP10/Connection/SenderLink.php` | Multi-frame transfer splitting |
| `src/AMQP10/Connection/DeliveryContext.php` | Add `modify()` |
| `src/AMQP10/Protocol/FrameBuilder.php` | Add `keepalive()` |
| `src/AMQP10/Connection/Connection.php` | Parse vhost from URI; expose `negotiatedIdleTimeout()`; parse idle timeout from server OPEN |
| `src/AMQP10/Client/Client.php` | `withTlsOptions()`; `reconnect()`; `session()` accessor; heartbeat timer; thread `maxFrameSize` to Publisher; remove autoReconnect config |
| `src/AMQP10/Client/Config.php` | Remove `autoReconnect`/`maxRetries`/`backoffMs`; add `$tlsOptions` |
| `src/AMQP10/Transport/RevoltTransport.php` | Add `$tlsOptions` constructor param; pass stream context on TLS connect |

## Testing Strategy

Integration tests use **testcontainers-php** (`testcontainers/testcontainers`) to spin up a RabbitMQ container automatically — no assumed running instance.

- **Credit replenishment**: unit test consumer receiving more than `$credit` messages continues receiving (mock transport feeding transfer frames)
- **Durable consumer**: verify `encodeSource()` emits correct durable/expiry-policy bytes; verify short-path not taken when durable/expiryPolicy set without filterMap; verify `TerminusDurability` and `ExpiryPolicy` enums encode correctly
- **Message durable flag**: verify `MessageEncoder` emits `durable=true` by default
- **Fire-and-forget**: verify `Publisher` sends `settled=true` in the transfer frame
- **Subject property**: verify `MessageEncoder` emits properties section with subject when only `withSubject()` is set (no other properties)
- **PublisherBuilder caching**: verify `send()` called twice reuses same `Publisher`; `publisher()` returns cached instance
- **Message wither API**: verify immutability — each wither returns a new instance, original unchanged
- **Heartbeats**: integration test verifying connection stays alive past idle timeout
- **Flow window sync**: verify FLOW frame uses session window values, not hardcoded 2048
- **TLS options**: integration test connecting with `verify_peer=false` to a TLS-enabled RabbitMQ container
- **Modified outcome**: verify `DeliveryContext::modify()` sends correct descriptor and fields
- **Multi-frame transfer**: unit test publish of a payload larger than `maxFrameSize` produces multiple TRANSFER frames with correct `more` flags; unit test consumer reassembles them into one `Message`
- **Virtual host**: verify URI `amqp://user:pass@host/my-vhost` sends `my-vhost` as OPEN hostname; verify bare URI sends actual hostname
- **Reconnection**: integration test simulating broker disconnect (stop/start container) and verifying `Consumer::run()` resumes automatically

## Verification

1. Run full unit test suite: `vendor/bin/phpunit`
2. Run integration tests (testcontainers-php starts RabbitMQ automatically): `vendor/bin/phpunit --group integration`
3. Run PHPStan at level 6: `vendor/bin/phpstan analyse`
4. Confirm `AutoReconnect` and `BlockingAdapter` no longer exist in `src/` or `tests/`
5. Update `README.md`: remove BlockingAdapter references; document TLS usage, durable consumers, fire-and-forget, virtual hosts, reconnection
6. Update `CHANGELOG.md` or release notes with all breaking removals (AutoReconnect, BlockingAdapter)
7. Manual smoke tests:
   - Publish 20 messages (> default credit of 10), verify all consumed
   - Durable consumer: disconnect and reconnect with same link name, verify delivery resumes
   - Publish a message > 64KB, verify it arrives intact
   - Connect with `amqps://` and TLS options, verify handshake
   - Kill broker mid-consume with `withReconnect()` set, verify consumer recovers

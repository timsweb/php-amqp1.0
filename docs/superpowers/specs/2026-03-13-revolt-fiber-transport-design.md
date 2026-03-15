# Revolt/Fiber Transport for PHP AMQP 1.0 Client

## Context

The AMQP 1.0 client currently uses a blocking I/O transport (`BlockingAdapter`) with `usleep(1000)` busy-wait polling in 4 locations (Session, Consumer, Publisher, Management). The 4 polling-loop sleeps waste CPU cycles and introduce ~1ms latency on every poll iteration. The original design spec planned for async runtime adapters (ReactPHP, Swoole, AMPHP) but none were built.

This design introduces a Revolt-based transport that uses PHP Fibers internally while keeping the public API synchronous-looking. The goal is better I/O efficiency, reduced latency, and a foundation for future concurrency features. `BlockingAdapter` is removed entirely — it is dead code once `RevoltTransport` is the sole transport.

## Decision

Use Revolt (`revolt/event-loop`) as a required dependency. Create a single `RevoltTransport` implementation that replaces busy-wait polling with Fiber suspension on socket readability. `BlockingAdapter` is deleted. The public API remains unchanged.

### Why Revolt

- Lightweight (~10 files, no transitive dependencies beyond PHP 8.2+)
- The PHP async standard — used by AMPHP v3 and adopted by Laravel
- Works transparently in synchronous contexts (`$suspension->suspend()` blocks in non-async code)
- PHP 8.2+ (already required by this library) guarantees Fiber availability

### Why Not Alternatives

- **Raw Fibers + stream_select**: Reinvents Revolt poorly, no ecosystem integration
- **Optional dependency with fallback**: Two code paths to maintain and test
- **OpenSwoole/Swoole adapter**: Niche ecosystem, lower priority

## Architecture

### New Class: `RevoltTransport`

**Location:** `src/AMQP10/Transport/RevoltTransport.php`

Implements `TransportInterface` using non-blocking streams and Revolt's event loop.

```
RevoltTransport
├── connect(string $uri): void
│   - Opens stream_socket_client in non-blocking mode
│   - Configures stream_set_blocking($stream, false)
│   - Accepts optional TLS context (see messaging gaps spec §11)
│
├── send(string $bytes): void
│   - Writes to non-blocking stream in a loop until all bytes written
│   - If a partial write returns 0 bytes (backpressure), suspends via
│     EventLoop::onWritable() using the same $resolved guard pattern
│     as read() to prevent double-resume
│   - In practice, writes to a local RabbitMQ socket rarely block;
│     the guard is a correctness measure, not a hot path
│
├── read(int $length = 4096): ?string
│   - Attempts fread() on non-blocking stream
│   - If no data available (empty string from fread), suspends until
│     socket is readable (see Timeout Handling below)
│   - Fiber yields until socket has data, then reads and returns
│   - Returns null only on genuine connection close (feof or fread false)
│
├── disconnect(): void
│   - Cancels any registered event loop callbacks
│   - Closes stream via fclose()
│
└── isConnected(): bool
    - Checks stream !== null && !feof($stream)
```

**`RevoltTransport::read()` semantics:** Suspends the Fiber when no data is immediately available, returns data when readable, returns `''` only when the per-read timeout fires (honouring the `TransportInterface` contract), and returns `null` only on genuine connection close. This changes the behaviour of code that calls `transport->read()` directly:

- `Session::readFrameOfType()` (line 114 reads, usleep on line 121): the `if ($data === '') { usleep(1000); }` branch at line 120 was unreachable with `BlockingAdapter` (which collapsed `''` to `null`). With `RevoltTransport` it is reachable when a per-read timeout fires. Remove the `usleep()` but keep the empty-string passthrough.
- `Session::nextFrame()` (line 172): currently blocks the calling thread for up to the stream timeout duration waiting for data, then returns `null`. With `RevoltTransport`, the `transport->read()` call instead suspends the Fiber until data arrives or the per-read timeout fires. On timeout, `RevoltTransport` returns `''`; `nextFrame()` maps `''` to `null` and returns it. Callers then hit their null-check branch — which is exactly where the `usleep(1000)` being removed lives. Removing the `usleep()` is safe because the Fiber already waited during `read()` rather than spinning. Outer deadline loops (`microtime()`) remain correct throughout.

**Implementation note on `send()` suspension:** In the write loop, `EventLoop::getSuspension()` must be called inside the loop body (once per iteration that blocks), not once before the loop. A single `$suspension` object cannot be reused across multiple suspend/resume cycles.

**Implementation note on `EventLoop::cancel()`:** Calling `cancel()` on an already-fired callback is a safe no-op in Revolt, so the two `cancel()` calls after `$suspension->suspend()` are always safe regardless of which callback won the race.

**`fillBuffer()` chunk-by-chunk behaviour:** `Connection::fillBuffer()` calls `read()` in a tight loop until enough bytes are buffered. When TCP delivers a large frame in multiple chunks, the loop may call `read()` again immediately after a successful read and find no data yet — `RevoltTransport` will suspend and wait for the next chunk. This is correct and expected; the `microtime()` deadline handles total timeouts.

### Timeout Handling

`RevoltTransport::read()` uses a race between `EventLoop::onReadable()` and `EventLoop::delay()`. A `$resolved` guard prevents a double-resume race condition if both callbacks fire in the same event loop tick:

```php
$suspension = EventLoop::getSuspension();
$resolved = false;

$readId = EventLoop::onReadable($stream, function() use ($suspension, &$resolved) {
    if (!$resolved) { $resolved = true; $suspension->resume(true); }
});
$timeoutId = EventLoop::delay($this->readTimeout, function() use ($suspension, &$resolved) {
    if (!$resolved) { $resolved = true; $suspension->resume(false); }
});

$hasData = $suspension->suspend();
EventLoop::cancel($readId);
EventLoop::cancel($timeoutId);
```

If `$hasData` is `false` (timeout won), `read()` returns `''`. Callers re-check their deadline and either loop again or throw `MessageTimeoutException`.

### Changes to Existing Classes

#### Transport Layer

| File | Change |
|------|--------|
| `TransportInterface.php` | **No change** |
| `BlockingAdapter.php` | **Deleted** — dead code once RevoltTransport is the sole transport |
| `RevoltTransport.php` | **New file** |

#### Connection Layer

| File | Change |
|------|--------|
| `Connection.php` | **No structural change.** `fillBuffer()` works correctly — each `read()` call suspends when no data is available and returns when a chunk arrives. The `microtime()` deadline remains as a safety timeout. |
| `Session.php` | **Remove `usleep(1000)` on line 121.** Keep the empty-string branch as a passthrough (no sleep). |

#### Messaging Layer

| File | Change |
|------|--------|
| `Consumer.php` | **Remove `usleep(1000)` on line 120.** `session->nextFrame()` chains to `transport->read()` which suspends. |
| `Publisher.php` | **Remove `usleep(1000)` on line 56.** Same reason. |

#### Management Layer

| File | Change |
|------|--------|
| `Management.php` | **Remove `usleep(1000)` on line 183.** Same reason. |

#### Client Layer

| File | Change |
|------|--------|
| `Client.php` | Remove `BlockingAdapter` import and instantiation; use `RevoltTransport` as the sole transport. |

### Dependency Changes

**composer.json:**
```json
{
    "require": {
        "revolt/event-loop": "^1.0"
    }
}
```

## Files to Create

| File | Purpose |
|------|---------|
| `src/AMQP10/Transport/RevoltTransport.php` | Revolt-based transport implementation |

## Files to Delete

| File | Reason |
|------|--------|
| `src/AMQP10/Transport/BlockingAdapter.php` | Dead code — replaced entirely by RevoltTransport |

## Files to Modify

| File | Summary |
|------|---------|
| `composer.json` | Add `revolt/event-loop` dependency |
| `src/AMQP10/Client/Client.php` | Replace BlockingAdapter with RevoltTransport |
| `src/AMQP10/Connection/Session.php` | Remove `usleep(1000)` |
| `src/AMQP10/Messaging/Consumer.php` | Remove `usleep(1000)` |
| `src/AMQP10/Messaging/Publisher.php` | Remove `usleep(1000)` |
| `src/AMQP10/Management/Management.php` | Remove `usleep(1000)` |

## Testing Strategy

### Unit Tests

- **`tests/Unit/Transport/RevoltTransportTest.php`** — Test using `stream_socket_pair()` for in-memory socket pairs:
  - Connect/disconnect lifecycle
  - `read()` returns data when available
  - `read()` returns `''` when no data and timeout fires
  - `read()` returns null on closed connection
  - `send()` writes all bytes
  - Timeout behaviour with the `$resolved` guard

### Existing Tests

- Unit tests use mocked `TransportInterface` — no changes needed
- Any unit tests referencing `BlockingAdapter` directly must be deleted

### Integration Tests

- Integration tests use testcontainers-php to spin up a RabbitMQ container — no assumed running instance
- End-to-end test: connect with RevoltTransport, publish a message, consume it, verify round-trip

## Verification

1. `composer require revolt/event-loop`
2. Run full unit test suite: `vendor/bin/phpunit`
3. Run integration tests (testcontainers-php spins up RabbitMQ automatically): `vendor/bin/phpunit --group integration`
4. Run PHPStan at level 6: `vendor/bin/phpstan analyse`
5. Confirm `usleep` is removed from: Session, Consumer, Publisher, Management
6. Confirm `BlockingAdapter` no longer exists anywhere in `src/` or `tests/`
7. Update `README.md` and any transport-related documentation to remove BlockingAdapter references

# Agent Guide — php-amqp1.0

This file is for AI agents working on this codebase. It covers architecture, conventions, and hard-won lessons from building and debugging this library.

---

## Project Overview

A modern PHP 8.1+ AMQP 1.0 client for RabbitMQ 4.0+. The library is hand-rolled — no AMQP framework dependency. Every byte on the wire is encoded by code in this repo.

**GitHub:** https://github.com/timsweb/php-amqp1.0
**Packagist:** `php-amqp10/client`
**Namespace:** `AMQP10\` → `src/AMQP10/`
**Tests:** `AMQP10\Tests\` → `tests/`

---

## Architecture

Strict layered architecture. Each layer only depends on the layer below it.

```
Client API          src/AMQP10/Client/
  └─ Messaging      src/AMQP10/Messaging/       (Consumer, Publisher, Message, etc.)
  └─ Management     src/AMQP10/Management/      (queue/exchange/binding operations)
       └─ Connection  src/AMQP10/Connection/    (Session, SenderLink, ReceiverLink, etc.)
            └─ Transport  src/AMQP10/Transport/ (BlockingAdapter, TransportInterface)
                 └─ Protocol  src/AMQP10/Protocol/ (TypeEncoder/Decoder, FrameParser, PerformativeEncoder, Descriptor)
```

**Single session model:** The `Client` opens one TCP connection and one AMQP session (channel 0), shared by management, publish, and consume operations. There is no connection pooling.

---

## Key Files

| File | Role |
|------|------|
| `src/AMQP10/Protocol/TypeEncoder.php` | Encodes PHP values → AMQP 1.0 wire bytes |
| `src/AMQP10/Protocol/TypeDecoder.php` | Decodes AMQP 1.0 wire bytes → PHP values |
| `src/AMQP10/Protocol/PerformativeEncoder.php` | Builds complete AMQP frames (ATTACH, FLOW, TRANSFER, etc.) |
| `src/AMQP10/Protocol/Descriptor.php` | Numeric constants for all AMQP performative/section descriptors |
| `src/AMQP10/Connection/Session.php` | Manages the frame buffer and `readFrameOfType()` |
| `src/AMQP10/Messaging/Consumer.php` | Builds the filter-set map and drives the receive loop |
| `src/AMQP10/Messaging/ConsumerBuilder.php` | Fluent API for setting up consumers |
| `tests/Integration/` | Integration tests — require a live RabbitMQ 4.x instance |

---

## Running Tests

```bash
# Unit tests only (no broker needed)
./vendor/bin/phpunit --testsuite Unit

# Integration tests (starts RabbitMQ automatically via testcontainers — requires Docker)
./vendor/bin/phpunit --testsuite Integration
```

Integration tests spin up a RabbitMQ 4.x container automatically via testcontainers-php. See `tests/Integration/RabbitMqTestCase.php`.

---

## Style Checking

This project uses **Laravel Pint** with the **PER 3.0** preset for automated style checking and fixes.

```bash
# Check code style
./vendor/bin/pint --test

# Auto-fix style issues
./vendor/bin/pint
```

**PER 3.0** (PHP-ECMA-Reference) is a modern, opinionated coding standard based on PSR-12 but with stricter rules. See the [PER specification](https://github.com/php-fig/per) for details.

Style checking runs automatically in CI via `./vendor/bin/pint --test`. New code should pass Pint before committing.

---

## Conventions

- All files have `declare(strict_types=1)` at the top.
- No magic, no dynamic property access, no reflection outside tests.
- `TypeDecoder` treats `STR8` and `SYM8` identically — both decode to PHP `string`. The distinction only matters on the wire (encoding side).
- Pre-encoded binary strings are passed around as `string` in PHP. The type system doesn't distinguish "AMQP-encoded bytes" from "plain string" — rely on naming conventions (`$encodedFoo`).

---

## Lessons Learned

These are non-obvious protocol and implementation details discovered through debugging against a real RabbitMQ broker. Read these before touching filters, session handling, or frame buffering.

### 1. AMQP filter-set: map KEY and described-type DESCRIPTOR can be different symbols

This is the single most confusing aspect of RabbitMQ's AMQP 1.0 filter implementation.

For the SQL filter in RabbitMQ 4.x:
- The **filter-set map key** (what RabbitMQ's `parse_filters/2` pattern-matches on) is: `sql-filter`
- The **described type's internal descriptor** (what `check_descriptor/1` validates) is: `amqp:sql-filter`

```php
// CORRECT
$mapKey     = TypeEncoder::encodeSymbol('sql-filter');        // map KEY
$descriptor = TypeEncoder::encodeSymbol('amqp:sql-filter');   // described type DESCRIPTOR
$pairs[$mapKey] = TypeEncoder::encodeDescribed($descriptor, TypeEncoder::encodeString($sql));

// WRONG — using the same symbol for both silently bypasses the filter
$descriptor = TypeEncoder::encodeSymbol('sql-filter');
$pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, ...);
```

When `check_descriptor` returns `error`, RabbitMQ silently ignores the filter and delivers all messages — no error, no rejection, just wrong behaviour.

Verified by decompiling `rabbit_amqp_filter_sql.beam` from the running container:
```bash
docker exec <container> erl -noshell -eval '
  {ok, {_, [{abstract_code, {_, AC}}]}} = beam_lib:chunks(
    "/opt/rabbitmq/plugins/rabbit-4.2.4/ebin/rabbit_amqp_filter_sql.beam",
    [abstract_code]),
  io:format("~p~n", [AC]), halt().'
```

### 2. RabbitMQ streams default to "next" offset — always specify an offset

For **stream queues**, a consumer without an explicit offset specification starts from `next` (i.e., only new messages published after the consumer connects). There is no equivalent of "deliver existing messages" like classic queues have.

If you publish messages and then subscribe without an offset, you receive nothing. Always add `->offset(Offset::first())` when you want to read existing stream content.

```php
// WRONG for streams — receives nothing if messages already exist
$client->consume($address)->filterBloom(['invoices'])->run();

// CORRECT
$client->consume($address)->offset(Offset::first())->filterBloom(['invoices'])->run();
```

This does not apply to classic or quorum queues.

### 3. Stale DETACH frames accumulate across operations on the shared session

The `Client` reuses a single session for all operations. `SenderLink::detach()` and `ReceiverLink::detach()` send DETACH frames but do **not** wait for the server's DETACH response (by design — it's best-effort on close). Those server DETACH responses sit in `Session::$pendingFrames`.

If a subsequent `readFrameOfType(Descriptor::ATTACH)` scan encounters one of these stale DETACHes, it must not throw unless the DETACH handle matches the handle currently being attached. This is why `readFrameOfType` accepts an optional `$rejectOnDetachHandle` parameter and `extractDetachHandle()` exists.

Without handle-aware DETACH detection, every consumer attach after a management close would spuriously throw.

### 4. Bloom filter: multiple values must be a LIST of strings, not an ARRAY of symbols

RabbitMQ's Erlang decoder expects:
- Single value: `{described, {symbol, "rabbitmq:stream-filter"}, {utf8, "invoices"}}`
- Multiple values: `{described, {symbol, "rabbitmq:stream-filter"}, {list, [{utf8, "a"}, {utf8, "b"}]}}`

Use `TypeEncoder::encodeList(array_map(fn($v) => TypeEncoder::encodeString($v), $values))` for multiple values — not `encodeSymbolArray()`, which produces an AMQP ARRAY of SYM8 elements.

### 5. SQL filter expression syntax: use `properties.subject`, not just `subject`

RabbitMQ's SQL lexer (`rabbit_amqp_sql_lexer.beam`) recognises the `properties.` prefix and maps it to the AMQP standard properties section via `rabbit_amqp_util:section_field_name_to_atom/1`. The expression `properties.subject = 'foo'` is valid and queries the AMQP standard properties `subject` field.

Application properties are accessed by bare key name: `region = 'EMEA'`.

### 6. Decompile RabbitMQ beam files when documentation is ambiguous

RabbitMQ's AMQP 1.0 filter documentation doesn't always match the implementation. When in doubt, decompile the relevant `.beam` file from a running container. The abstract code is readable Erlang-like AST. Key files:

```
/opt/rabbitmq/plugins/rabbit-4.2.4/ebin/rabbit_amqp_filter.beam       # parse_filters/2
/opt/rabbitmq/plugins/rabbit-4.2.4/ebin/rabbit_amqp_filter_sql.beam   # SQL parse/eval
/opt/rabbitmq/plugins/rabbit-4.2.4/ebin/rabbit_amqp_util.beam         # section_field_name_to_atom/1
/opt/rabbitmq/plugins/rabbit-4.2.4/ebin/rabbit_amqp_sql_lexer.beam    # tokenizer
```

### 7. Background EventLoop watchers MUST be unreferenced

The library uses a "transparent event loop" pattern: `RevoltTransport::send()` and `read()` detect `Fiber::getCurrent()` and, when called outside a Fiber, wrap operations in `EventLoop::queue() + EventLoop::run()`. This only terminates correctly when no **referenced** persistent watchers are alive.

Any `EventLoop::repeat()` or other persistent watcher that exists for background purposes (e.g. the AMQP heartbeat timer in `Client::connect()`) must be marked as background work with `EventLoop::unreference()` immediately after creation:

```php
$this->heartbeatTimerId = EventLoop::repeat($intervalSec, $callback);
EventLoop::unreference($this->heartbeatTimerId); // REQUIRED — omitting this causes every one-shot operation to hang forever
```

**Unreferenced watchers still fire** — the connection stays alive — but they no longer prevent `EventLoop::run()` from returning when the real work is done.

The canary tests in `tests/Unit/Transport/EventLoopInvariantsTest.php` guard against this regression. If those tests hang, a referenced persistent watcher has been introduced somewhere.

### 8. Management instances track their own closed state; Client caches them

`Client::management()` caches the `Management` instance to avoid leaking AMQP links on repeated calls. However, callers may close management explicitly with `$mgmt->close()` and then call `$client->management()` again expecting a fresh, usable instance.

To support this, `Management` tracks whether it has been closed (`isClosed()`), and `Client::management()` creates a new instance if the cached one is closed:

```php
if ($this->management === null || $this->management->isClosed()) {
    $this->management = new Management($this->session(), $this->config->timeout);
}
```

**Why this matters:** a closed `Management` has detached its AMQP sender/receiver links. Using it for further requests sends frames with dead handles. The broker responds with an error frame that `awaitResponse()` silently ignores (it only looks for TRANSFER frames), causing a 30-second timeout spin before throwing `ManagementException`.

### 9. Unit tests passing does not mean the wire format is correct

`TypeDecoder` decodes both `STR8` and `SYM8` to plain PHP strings, so unit tests asserting decoded map structure can't tell whether the encoder chose the right AMQP type. Always validate filter behaviour against a live RabbitMQ instance when changing encoding logic.

---

## Debugging Tips

**Capture what's being sent:** Add a hex dump before `$session->transport()->send(...)` in `ReceiverLink::attach()` to inspect the raw ATTACH frame.

**RabbitMQ logs:** Run with `docker logs -f <container>`. A "client unexpectedly closed TCP connection" warning after ~29s means the PHP side timed out waiting for an ATTACH response — RabbitMQ likely rejected the frame silently or via connection CLOSE.

**Beam decompilation:** See Lesson 6 above. The `abstract_code` chunk gives readable function bodies. Pipe through `grep` or write to a file.

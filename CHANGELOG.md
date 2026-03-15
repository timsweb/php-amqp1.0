# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-03-15

Initial release.

### Added

**Core protocol**
- AMQP 1.0 type system: encoder and decoder for all primitive and composite types
- Frame builder and parser with stream-safe TCP frame reassembly
- Performative encoder for all AMQP 1.0 and SASL performatives
- Descriptor constants for AMQP 1.0 performatives and message sections
- Session with spec-compliant BEGIN handshake and shared frame buffer (`readFrameOfType` / `nextFrame`)
- SenderLink and ReceiverLink with spec-compliant ATTACH handshake (awaits server response)
- Multi-frame TRANSFER support for large messages

**Transport**
- `RevoltTransport` — async transport using [Revolt](https://revolt.run/) Fibers for non-blocking I/O
- TLS support with configurable options (`cafile`, `verify_peer`, etc.)
- Transparent event loop: one-shot publish/connect operations work without event loop boilerplate
- Keepalive heartbeat using AMQP 1.0 idle-timeout negotiation

**Connection**
- SASL PLAIN and EXTERNAL authentication
- Virtual host from URI path (including `%2F` for the default `/` vhost)
- Server idle-timeout parsing from OPEN frame
- Typed exception hierarchy (`AmqpException`, `AuthenticationException`, `ConnectionException`, etc.)

**Messaging**
- `Publisher` / `PublisherBuilder` with fire-and-forget (pre-settled) mode and link caching
- `Consumer` / `ConsumerBuilder` with:
  - Configurable flow-control credit and credit replenishment
  - Stable link names for durable consumers
  - TerminusDurability and ExpiryPolicy configuration
  - Reconnect with configurable retries and backoff
  - Graceful shutdown via `stopOnSignal()` (requires `ext-pcntl`)
  - Consumer stop control via cached `Consumer` reference (`consumer()` before `run()`)
  - Idle timeout configuration (`withIdleTimeout()`)
- `Message` with fluent wither API (`withSubject`, `withContentType`, `withMessageId`, `withCorrelationId`, `withApplicationProperty`, `withTtl`, `withDurable`)
- `DeliveryContext` with accept / release / reject / modify outcomes
- Modified outcome for dead-letter and retry-elsewhere patterns

**Stream filtering** (RabbitMQ streams)
- Bloom filter (`filterBloom()`) — efficient chunk-level Stage 1 filtering with `matchUnfiltered` support
- AMQP SQL filter (`filterAmqpSql()` / `filterSql()`) — Stage 2 server-side SQL expressions
- JMS SQL selector (`filterJms()`) — ActiveMQ/Artemis compatibility
- Offset-based consume (`Offset::offset()`, `Offset::first()`, `Offset::last()`, `Offset::timestamp()`)

**Management**
- `Management` API over HTTP-over-AMQP on the `/management` link
- Declare and delete queues (classic, quorum, stream)
- Declare and delete exchanges (direct, fanout, topic, headers)
- Bind queues to exchanges with routing key

**Client API**
- `Client` facade with fluent configuration (`withSasl()`, `withTlsOptions()`)
- RabbitMQ AMQP 1.0 v2 address format (`/queues/name`, `/exchanges/name/routing-key`)

**Testing**
- 262 unit tests, 507 assertions
- Integration test suite using [testcontainers-php](https://github.com/testcontainers/testcontainers-php) (requires Docker)

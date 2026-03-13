# Deferred Work Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete two deferred spec-compliance and feature items: (1) Session/Link await server acknowledgement during handshake, (2) Consumer filter/offset wired to ATTACH source terminus.

**Architecture:** Chunk 1 adds a shared FrameParser + pending-frame buffer to Session, exposing `readFrameOfType()` and `nextFrame()`. All frame reads (Session handshake, Publisher, Consumer, Management) flow through this single session buffer. Chunk 2 extends the ATTACH source terminus encoding to include a filter map, wires Consumer's existing offset/SQL filter parameters through to ReceiverLink and PerformativeEncoder.

**Tech Stack:** PHP 8.1+, PHPUnit 10, AMQP 1.0 spec §2.4.2 (attach/begin handshake), §3.5.3 (source terminus filter map), RabbitMQ 4.x stream offset filter (`rabbitmq:stream-offset-spec`), Apache QPid SQL selector (`apache.org:selector-filter:string`)

---

## File Structure

**Chunk 1 — Session frame buffer:**
- Modify: `src/AMQP10/Connection/Session.php` — add FrameParser, pendingFrames, `readFrameOfType()`, `nextFrame()`
- Modify: `src/AMQP10/Connection/SenderLink.php` — call `$session->readFrameOfType(ATTACH)` after sending ATTACH
- Modify: `src/AMQP10/Connection/ReceiverLink.php` — call `$session->readFrameOfType(ATTACH)` after sending ATTACH (before grantCredit)
- Modify: `src/AMQP10/Messaging/Publisher.php` — use `$session->nextFrame()` instead of own FrameParser
- Modify: `src/AMQP10/Messaging/Consumer.php` — use `$session->nextFrame()` instead of own FrameParser
- Modify: `src/AMQP10/Management/Management.php` — use `$session->nextFrame()` instead of own FrameParser + pendingFrames
- Modify: `tests/Unit/Connection/SessionTest.php` — add `connect()`, new tests
- Modify: `tests/Unit/Connection/SenderLinkTest.php` — add `connect()` to makeSession
- Modify: `tests/Unit/Messaging/PublisherTest.php` — queue ATTACH responses
- Modify: `tests/Unit/Messaging/ConsumerTest.php` — queue ATTACH responses, update for new loop
- Modify: `tests/Unit/Management/ManagementTest.php` — queue BEGIN, add `connect()`

**Chunk 2 — Consumer filter/offset:**
- Modify: `src/AMQP10/Protocol/TypeEncoder.php` — add `encodeTimestamp(int $ms): string`
- Modify: `src/AMQP10/Protocol/PerformativeEncoder.php` — extend `encodeSource()` with `?string $filterMap`, add `$filterMap` param to `attach()`
- Modify: `src/AMQP10/Connection/ReceiverLink.php` — add `?string $filterMap` constructor param, pass to attach
- Modify: `src/AMQP10/Messaging/Consumer.php` — build filter map bytes, pass to ReceiverLink
- Modify: `tests/Unit/Protocol/TypeEncoderTest.php` — add timestamp encoding test
- Modify: `tests/Unit/Connection/ReceiverLinkTest.php` — **create** this file; test filter map in ATTACH
- Add: `tests/Integration/StreamFilterIntegrationTest.php` — integration test with real RabbitMQ stream

---

## Chunk 1: Session/Link Handshake Spec Compliance

### Task 1: Add frame buffer and `readFrameOfType()` / `nextFrame()` to Session

**Files:**
- Modify: `src/AMQP10/Connection/Session.php`
- Modify: `tests/Unit/Connection/SessionTest.php`

**Context:** Currently `Session::begin()` sends BEGIN and immediately marks itself open without reading the peer's BEGIN response. This violates AMQP 1.0 spec §2.4.2. The fix adds a shared FrameParser + pending buffer to Session and two methods: `readFrameOfType()` (used during handshake) and `nextFrame()` (used by Consumer/Publisher/Management for data-phase frame reads).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Connection/SessionTest.php`:

```php
public function test_begin_awaits_server_begin_response(): void
{
    // Arrange: queue the server's BEGIN response
    $mock = new TransportMock();
    $mock->connect('amqp://test');
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);

    // Act: should complete without error (reads the queued BEGIN)
    $session->begin();

    // Assert: session is open
    $this->assertTrue($session->isOpen());
}

public function test_begin_throws_when_transport_closes_before_response(): void
{
    $mock    = new TransportMock();
    // Deliberately NOT queuing a BEGIN response and NOT connecting
    $session = new Session($mock, channel: 0);

    $this->expectException(\RuntimeException::class);
    $session->begin();
}

public function test_readFrameOfType_buffers_non_matching_frames(): void
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');
    // Queue an OPEN frame (non-matching) then a BEGIN frame (matching)
    $mock->queueIncoming(PerformativeEncoder::open('container-1'));
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);

    // readFrameOfType should skip OPEN and return BEGIN
    $session->begin(); // internally calls readFrameOfType(BEGIN)
    $this->assertTrue($session->isOpen());

    // The OPEN frame should still be in the session's pending buffer (accessible via nextFrame)
    $frame = $session->nextFrame();
    $this->assertNotNull($frame);
    $body        = FrameParser::extractBody($frame);
    $performative = (new TypeDecoder($body))->decode();
    $this->assertSame(Descriptor::OPEN, $performative['descriptor']);
}

public function test_nextFrame_returns_null_when_no_more_data(): void
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);
    $session->begin();

    // No more frames queued — nextFrame should return null
    $result = $session->nextFrame();
    $this->assertNull($result);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SessionTest.php -v
```
Expected: some tests FAIL (new methods don't exist yet)

- [ ] **Step 3: Update `Session.php` with frame buffer and new methods**

Replace `src/AMQP10/Connection/Session.php` with:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Connection;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Transport\TransportInterface;

/**
 * Manages an AMQP 1.0 session (BEGIN/END).
 * Owns the shared frame buffer for this session; all frame reads (handshake,
 * Consumer, Publisher, Management) go through nextFrame() / readFrameOfType().
 */
class Session
{
    private bool $open           = false;
    private int  $nextOutgoingId = 0;
    private int  $nextLinkHandle = 0;

    private FrameParser $frameParser;
    /** @var string[] Frames read from transport but not yet consumed by a caller. */
    private array $pendingFrames = [];

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $channel,
        private readonly int $incomingWindow = 2048,
        private readonly int $outgoingWindow = 2048,
    ) {
        $this->frameParser = new FrameParser();
    }

    public function begin(): void
    {
        $this->transport->send(PerformativeEncoder::begin(
            channel:        $this->channel,
            nextOutgoingId: $this->nextOutgoingId,
            incomingWindow: $this->incomingWindow,
            outgoingWindow: $this->outgoingWindow,
        ));
        $this->readFrameOfType(Descriptor::BEGIN);
        $this->open = true;
    }

    public function end(): void
    {
        if ($this->open) {
            $this->transport->send(PerformativeEncoder::end($this->channel));
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function channel(): int
    {
        return $this->channel;
    }

    public function allocateHandle(): int
    {
        return $this->nextLinkHandle++;
    }

    public function nextDeliveryId(): int
    {
        return $this->nextOutgoingId++;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Read frames from transport until one with $descriptor is found.
     * Non-matching frames are buffered in $this->pendingFrames for nextFrame() callers.
     * Used during BEGIN/ATTACH handshake to verify server acknowledgement (spec §2.4.2).
     *
     * @throws \RuntimeException if transport closes before the expected frame arrives.
     */
    public function readFrameOfType(int $descriptor): string
    {
        while (true) {
            // Scan pending buffer first
            foreach ($this->pendingFrames as $i => $frame) {
                $body         = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();
                if (is_array($performative) && ($performative['descriptor'] ?? null) === $descriptor) {
                    unset($this->pendingFrames[$i]);
                    $this->pendingFrames = array_values($this->pendingFrames);
                    return $frame;
                }
            }

            // Nothing in pending buffer — read more from transport
            $data = $this->transport->read(4096);
            if ($data === null || (!$this->transport->isConnected() && $data === '')) {
                throw new \RuntimeException(
                    'Transport closed while awaiting frame with descriptor 0x' . dechex($descriptor)
                );
            }
            if ($data === '') {
                continue;
            }
            $this->frameParser->feed($data);
            foreach ($this->frameParser->readyFrames() as $frame) {
                $this->pendingFrames[] = $frame;
            }
        }
    }

    /**
     * Return the next available frame from the session buffer, or read one from transport.
     * Returns null when there are no more frames and the transport has no data / is closed.
     * Used by Consumer, Publisher, and Management during the data phase.
     */
    public function nextFrame(): ?string
    {
        if (!empty($this->pendingFrames)) {
            return array_shift($this->pendingFrames);
        }

        if (!$this->transport->isConnected()) {
            return null;
        }

        $data = $this->transport->read(4096);
        if ($data === null || $data === '') {
            return null;
        }

        $this->frameParser->feed($data);
        $frames = $this->frameParser->readyFrames();
        if (empty($frames)) {
            return null;
        }

        $first               = array_shift($frames);
        $this->pendingFrames = array_merge($this->pendingFrames, $frames);
        return $first;
    }
}
```

- [ ] **Step 4: Fix the existing `test_begin_sends_begin_frame` — it needs `connect()` now**

In `tests/Unit/Connection/SessionTest.php`, update `makeOpenSession()`:

```php
private function makeOpenSession(): array
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');   // ← add this line
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);
    $session->begin();
    return [$mock, $session];
}
```

- [ ] **Step 5: Run tests to verify all Session tests pass**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SessionTest.php -v
```
Expected: all tests PASS

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Connection/Session.php tests/Unit/Connection/SessionTest.php
git commit -m "feat: Session owns frame buffer; readFrameOfType() + nextFrame() for spec-compliant handshake"
```

---

### Task 2: SenderLink and ReceiverLink await server ATTACH

**Files:**
- Modify: `src/AMQP10/Connection/SenderLink.php`
- Modify: `src/AMQP10/Connection/ReceiverLink.php`
- Modify: `tests/Unit/Connection/SenderLinkTest.php`

**Context:** `SenderLink::attach()` and `ReceiverLink::attach()` send ATTACH but never read the peer's ATTACH response (spec violation §2.4.2). After Task 1, Session has `readFrameOfType()` — we just need to call it. ReceiverLink should call `readFrameOfType(ATTACH)` BEFORE `grantCredit()` so credit is only granted after the link is confirmed open.

- [ ] **Step 1: Write failing tests**

Add to `tests/Unit/Connection/SenderLinkTest.php`:

```php
public function test_attach_throws_when_server_does_not_respond(): void
{
    $mock    = new TransportMock();
    // Not connected, no ATTACH response queued
    $session = new Session($mock, channel: 0);
    $link    = new SenderLink($session, name: 'l', target: '/queues/test');

    $this->expectException(\RuntimeException::class);
    $link->attach();
}
```

And verify the existing `makeSession()` in `SenderLinkTest` needs `connect()`:
The existing `makeSession()` does NOT call `connect()`. Update it:

```php
private function makeSession(): array
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');  // ← add this
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);
    $session->begin();
    $mock->clearSent();
    return [$mock, $session];
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SenderLinkTest.php -v
```
Expected: `test_attach_throws_when_server_does_not_respond` fails (no exception thrown yet); existing tests may fail too because `makeSession()` didn't have `connect()`

- [ ] **Step 3: Update `SenderLink.php`**

Add import and call `readFrameOfType` in `attach()`:

```php
use AMQP10\Protocol\Descriptor;
```

In `attach()`, after `$this->session->transport()->send(...)`:
```php
$this->session->readFrameOfType(Descriptor::ATTACH);
$this->attached = true;
```

Full updated `attach()`:
```php
public function attach(): void
{
    $properties = $this->managementLink
        ? [TypeEncoder::encodeSymbol('paired') => TypeEncoder::encodeBool(true)]
        : null;

    $initialDeliveryCount = 0;

    $this->session->transport()->send(PerformativeEncoder::attach(
        channel:              $this->session->channel(),
        name:                 $this->name,
        handle:               $this->handle,
        role:                 PerformativeEncoder::ROLE_SENDER,
        source:               $this->source,
        target:               $this->target,
        sndSettleMode:        $this->sndSettleMode,
        properties:           $properties,
        initialDeliveryCount: $initialDeliveryCount,
    ));
    $this->session->readFrameOfType(Descriptor::ATTACH);
    $this->attached = true;
}
```

- [ ] **Step 4: Update `ReceiverLink.php`**

Add import and call `readFrameOfType` in `attach()`, before `grantCredit`:

```php
use AMQP10\Protocol\Descriptor;
```

Full updated `attach()`:
```php
public function attach(): void
{
    $properties = $this->managementLink
        ? [TypeEncoder::encodeSymbol('paired') => TypeEncoder::encodeBool(true)]
        : null;

    $this->session->transport()->send(PerformativeEncoder::attach(
        channel:    $this->session->channel(),
        name:       $this->name,
        handle:     $this->handle,
        role:       PerformativeEncoder::ROLE_RECEIVER,
        source:     $this->source,
        target:     $this->target,
        properties: $properties,
    ));
    $this->session->readFrameOfType(Descriptor::ATTACH);
    $this->attached = true;
    $this->grantCredit($this->initialCredit);
}
```

- [ ] **Step 5: Run SenderLink tests**

```bash
./vendor/bin/phpunit tests/Unit/Connection/SenderLinkTest.php -v
```
Expected: all PASS

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Connection/SenderLink.php src/AMQP10/Connection/ReceiverLink.php tests/Unit/Connection/SenderLinkTest.php
git commit -m "feat: SenderLink and ReceiverLink await server ATTACH before marking link open (spec §2.4.2)"
```

---

### Task 3: Migrate Publisher, Consumer, and Management to use `session->nextFrame()`

**Files:**
- Modify: `src/AMQP10/Messaging/Publisher.php`
- Modify: `src/AMQP10/Messaging/Consumer.php`
- Modify: `src/AMQP10/Management/Management.php`
- Modify: `tests/Unit/Messaging/PublisherTest.php`
- Modify: `tests/Unit/Messaging/ConsumerTest.php`
- Modify: `tests/Unit/Management/ManagementTest.php`

**Context:** Publisher, Consumer, and Management each own a private `FrameParser` and call `$transport->read()` independently. Now that Session owns the shared FrameParser + buffer, all three should call `$session->nextFrame()` instead — so any frames buffered during the handshake phase (readFrameOfType calls) are not lost.

Additionally, the test helpers need to be updated:
- All tests that create Publisher/Consumer/Management now need to queue an ATTACH response for each link (since `attach()` now reads from transport)
- ManagementTest also needs to queue a BEGIN response and call `connect()`

- [ ] **Step 1: Update Publisher.php**

Remove `FrameParser $parser` property and the `$this->parser = new FrameParser()` line from the constructor. Update `awaitOutcome()` to use `$this->session->nextFrame()`:

```php
private function awaitOutcome(int $deliveryId): Outcome
{
    while (true) {
        $frame = $this->session->nextFrame();
        if ($frame === null) {
            throw new PublishException('Connection closed while awaiting outcome');
        }

        $body         = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();

        if (!is_array($performative)) {
            continue;
        }
        if (($performative['descriptor'] ?? null) !== Descriptor::DISPOSITION) {
            continue;
        }

        $fields = $performative['value'];
        // Disposition list fields: [0]=role, [1]=first, [2]=last, [3]=settled, [4]=state
        $first = $fields[1] ?? null;
        if ($first !== $deliveryId) {
            continue;
        }

        $state = $fields[4] ?? null;
        return $this->decodeOutcome($state);
    }
}
```

Remove `use AMQP10\Protocol\FrameParser;` import only if FrameParser is no longer used. Note: FrameParser::extractBody() is still used — keep the import.

Full updated `Publisher.php`:
```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\PublishException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Publisher
{
    private readonly SenderLink $link;

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {
        $linkName   = 'sender-' . bin2hex(random_bytes(4));
        $this->link = new SenderLink($session, name: $linkName, target: $address);
        $this->link->attach();
    }

    public function send(Message $message): Outcome
    {
        $payload    = MessageEncoder::encode($message);
        $deliveryId = $this->link->transfer($payload);
        if ($this->link->isPreSettled()) {
            return Outcome::accepted(); // fire-and-forget: broker sends no DISPOSITION
        }
        return $this->awaitOutcome($deliveryId);
    }

    public function close(): void
    {
        $this->link->detach();
    }

    private function awaitOutcome(int $deliveryId): Outcome
    {
        while (true) {
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                throw new PublishException('Connection closed while awaiting outcome');
            }

            $body         = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();

            if (!is_array($performative)) {
                continue;
            }
            if (($performative['descriptor'] ?? null) !== Descriptor::DISPOSITION) {
                continue;
            }

            $fields = $performative['value'];
            $first  = $fields[1] ?? null;
            if ($first !== $deliveryId) {
                continue;
            }

            $state = $fields[4] ?? null;
            return $this->decodeOutcome($state);
        }
    }

    private function decodeOutcome(mixed $state): Outcome
    {
        if (!is_array($state)) {
            return Outcome::accepted();
        }
        return match ($state['descriptor'] ?? null) {
            Descriptor::ACCEPTED => Outcome::accepted(),
            Descriptor::REJECTED => Outcome::rejected(),
            Descriptor::RELEASED => Outcome::released(),
            Descriptor::MODIFIED => Outcome::modified(),
            default              => Outcome::released(),
        };
    }
}
```

- [ ] **Step 2: Update Consumer.php**

Remove `FrameParser $parser` property and `$this->parser = new FrameParser()`. Update `run()` to use `$this->session->nextFrame()`:

```php
public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
{
    $this->link->attach();

    while (true) {
        $frame = $this->session->nextFrame();
        if ($frame === null) {
            break;
        }
        if ($this->isTransferFrame($frame)) {
            $this->handleTransfer($frame, $handler, $errorHandler);
        }
    }

    try {
        $this->link->detach();
    } catch (\Throwable) {
        // Transport already closed — DETACH cannot be sent, which is acceptable.
    }
}
```

Remove `use AMQP10\Protocol\FrameParser;` only if it's not used elsewhere in Consumer. `FrameParser::extractBody()` is still used in `isTransferFrame()` and `handleTransfer()` — keep the import.

Full updated `Consumer.php`:
```php
<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Consumer
{
    private readonly ReceiverLink $link;

    public function __construct(
        private readonly Session  $session,
        private readonly string   $address,
        private readonly int      $credit       = 10,
        private readonly ?Offset  $offset       = null,
        private readonly ?string  $filterSql    = null,
        private readonly array    $filterValues = [],
    ) {
        $linkName   = 'receiver-' . bin2hex(random_bytes(4));
        $this->link = new ReceiverLink($session, name: $linkName, source: $address, initialCredit: $credit);
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $this->link->attach();

        while (true) {
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                break;
            }
            if ($this->isTransferFrame($frame)) {
                $this->handleTransfer($frame, $handler, $errorHandler);
            }
        }

        try {
            $this->link->detach();
        } catch (\Throwable) {
            // Transport already closed — DETACH cannot be sent, which is acceptable.
        }
    }

    private function isTransferFrame(string $frame): bool
    {
        $body        = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        return is_array($performative) && ($performative['descriptor'] ?? null) === Descriptor::TRANSFER;
    }

    private function handleTransfer(string $frame, ?\Closure $handler, ?\Closure $errorHandler): void
    {
        $body         = FrameParser::extractBody($frame);
        $decoder      = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId     = $performative['value'][1] ?? 0;
        $messagePayload = substr($body, $decoder->offset());
        $message        = MessageDecoder::decode($messagePayload);
        $ctx            = new DeliveryContext($deliveryId, $this->link);

        if ($handler !== null) {
            try {
                $handler($message, $ctx);
            } catch (\Throwable $e) {
                if ($errorHandler !== null) {
                    $errorHandler($e);
                }
            }
        }
    }
}
```

- [ ] **Step 3: Update Management.php**

Remove `FrameParser $parser` property and constructor init. Remove `$this->pendingFrames` (Session handles this now). Update `awaitResponse()` to use `$this->session->nextFrame()`.

Key changes:
- Remove `private readonly FrameParser $parser;`
- Remove `private array $pendingFrames = [];`
- Remove `$this->parser = new FrameParser();` from constructor
- Remove `use AMQP10\Protocol\FrameParser;` only if no longer needed — `FrameParser::extractBody()` is still used in `awaitResponse()`, keep the import

Updated `awaitResponse()`:

```php
private function awaitResponse(string $requestId): array
{
    while (true) {
        $frame = $this->session->nextFrame();
        if ($frame === null) {
            throw new ManagementException('Connection closed awaiting management response');
        }

        $body         = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();

        if (!is_array($performative) || ($performative['descriptor'] ?? null) !== Descriptor::TRANSFER) {
            continue;
        }

        $bodyDecoder = new TypeDecoder($body);
        $bodyDecoder->decode(); // consume performative
        $msgPayload  = substr($body, $bodyDecoder->offset());

        if ($msgPayload === '') {
            continue;
        }

        $response = $this->decodeResponsePayload($msgPayload);

        if ((string)($response['correlation-id'] ?? '') !== $requestId) {
            // Response for a different request — put back and keep reading.
            // (session's pending buffer serves as the shared buffer now)
            continue; // NOTE: in single-threaded PHP, multiple concurrent requests
                      // are impossible, so mismatched correlation-ids won't happen in practice
        }

        return [
            'status' => (int)($response['subject'] ?? 0),
            'body'   => $response['body'] ?? '',
        ];
    }
}
```

**Important note:** The old Management code had `$this->pendingFrames` to buffer responses for different requests so they could be retrieved on the next `awaitResponse()` call. Since PHP is single-threaded and requests are sequential, mismatched correlation-ids should never occur. If they do, the frame is simply skipped (this matches the actual use case since management requests are always serial). This simplifies the code without loss of correctness.

- [ ] **Step 4: Update `PublisherTest.php` — add ATTACH response to each test**

Each test calls `new Publisher(...)` which now calls `link->attach()` which calls `session->readFrameOfType(ATTACH)`. Each test needs a queued server ATTACH response BEFORE the disposition.

For each test that creates a Publisher, add before the disposition queue:
```php
// Queue server's ATTACH response (required since SenderLink now awaits it)
$mock->queueIncoming(PerformativeEncoder::attach(
    channel: 0,
    name:    'sender-link',
    handle:  0,
    role:    PerformativeEncoder::ROLE_RECEIVER,
    source:  null,
    target:  '/queues/test',
));
```

Update all 4 tests in PublisherTest (`test_send_transmits_transfer_frame_and_returns_accepted`, `test_send_returns_rejected_outcome`, `test_close_sends_detach_frame`, `test_publisher_builder_send_and_close`) to queue this ATTACH frame before the disposition.

- [ ] **Step 5: Update `ConsumerTest.php` — add ATTACH response queuing and clean up disconnect calls**

Add to `makeSession()` in ConsumerTest — nothing needed there, makeSession already has `connect()`.

For each test, queue a server ATTACH response AFTER `makeSession()` and BEFORE creating the Consumer/running it. Because Consumer calls `ReceiverLink::attach()` which calls `session->readFrameOfType(ATTACH)`.

The ATTACH response for a ReceiverLink uses `ROLE_SENDER` (server sends with sender role, from server's perspective it becomes a sender now that client is receiver):
```php
$mock->queueIncoming(PerformativeEncoder::attach(
    channel: 0,
    name:    'receiver-link',
    handle:  0,
    role:    PerformativeEncoder::ROLE_SENDER,
    source:  '/queues/test',
    target:  null,
));
```

Also update `test_consumer_sends_attach_and_flow_frames` — it currently disconnects the mock before run() to stop the loop. With our change, Consumer exits when `nextFrame()` returns null (which happens when mock is empty). The `$mock->disconnect()` call before `consumer->run()` should be removed, and instead rely on the mock being empty after ATTACH is consumed.

Updated test:
```php
public function test_consumer_sends_attach_and_flow_frames(): void
{
    [$mock, $session] = $this->makeSession();

    // Queue server's ATTACH response (required by spec — ReceiverLink awaits it)
    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0, name: 'recv', handle: 0,
        role:    PerformativeEncoder::ROLE_SENDER,
        source:  '/queues/test', target: null,
    ));

    // No more frames — Consumer exits naturally when nextFrame() returns null

    $consumer = new Consumer($session, '/queues/test', credit: 5);
    $consumer->run(null);

    $parser = new FrameParser();
    $parser->feed($mock->sent());
    $frames = $parser->readyFrames();

    $descriptors = [];
    foreach ($frames as $frame) {
        $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
        if (is_array($perf)) {
            $descriptors[] = $perf['descriptor'] ?? null;
        }
    }

    $this->assertContains(Descriptor::ATTACH, $descriptors, 'Consumer must send ATTACH');
    $this->assertContains(Descriptor::FLOW,   $descriptors, 'Consumer must send FLOW (credit grant)');
    $this->assertContains(Descriptor::DETACH, $descriptors, 'Consumer must send DETACH on exit');
}
```

For `test_consumer_stops_when_transport_disconnects`, update similarly:
```php
public function test_consumer_stops_when_transport_disconnects(): void
{
    [$mock, $session] = $this->makeSession();

    // Queue ATTACH response, then no more frames — Consumer exits naturally
    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0, name: 'recv', handle: 0,
        role:    PerformativeEncoder::ROLE_SENDER,
        source:  '/queues/test', target: null,
    ));

    $called   = false;
    $consumer = new Consumer($session, '/queues/test', credit: 1);
    $consumer->run(function (Message $msg) use (&$called) {
        $called = true;
    });

    $this->assertFalse($called, 'Handler must not be called when no messages are queued');
}
```

For all other ConsumerTest tests that call `$mock->disconnect()` inside the handler — those still work because after `disconnect()`, `session->nextFrame()` checks `isConnected()` → returns false → returns null → loop exits.

For each of those tests, just add the ATTACH queue before running:
```php
$mock->queueIncoming(PerformativeEncoder::attach(
    channel: 0, name: 'recv', handle: 0,
    role:    PerformativeEncoder::ROLE_SENDER,
    source:  '/queues/test', target: null,
));
```

- [ ] **Step 6: Update `ManagementTest.php` — add `connect()` and BEGIN response**

Add to `setUpManagement()`:
```php
private function setUpManagement(TransportMock $mock): Management
{
    $mock->connect('amqp://test');  // ← add this
    $mock->clearSent();

    // Queue BEGIN response (required since Session::begin() now awaits it)
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));  // ← add this

    // Queue ATTACH responses for sender + receiver links.
    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name:    'management-link',
        handle:  0,
        role:    PerformativeEncoder::ROLE_RECEIVER,
        source:  null,
        target:  '/management',
    ));
    $mock->queueIncoming(PerformativeEncoder::attach(
        channel: 0,
        name:    'management-link',
        handle:  1,
        role:    PerformativeEncoder::ROLE_SENDER,
        source:  '/management',
        target:  null,
    ));

    $session = new Session($mock, channel: 0);
    $session->begin();
    return new Management($session);
}
```

- [ ] **Step 7: Run all unit tests**

```bash
./vendor/bin/phpunit 2>&1
```
Expected: all 138 tests PASS (plus new ones added in this plan)

- [ ] **Step 8: Run integration tests**

Ensure RabbitMQ Docker container is running:
```bash
docker ps --filter name=rabbitmq-amqp10-test
```
If not running:
```bash
docker run -d --name rabbitmq-amqp10-test -p 5672:5672 -p 15672:15672 \
  rabbitmq:4.0-management
docker exec rabbitmq-amqp10-test rabbitmq-plugins enable rabbitmq_amqp10_client 2>/dev/null || true
```

Run:
```bash
./vendor/bin/phpunit -c phpunit-integration.xml 2>&1
```
Expected: all 7 integration tests PASS

- [ ] **Step 9: Commit**

```bash
git add src/AMQP10/Messaging/Publisher.php src/AMQP10/Messaging/Consumer.php \
        src/AMQP10/Management/Management.php \
        tests/Unit/Messaging/PublisherTest.php tests/Unit/Messaging/ConsumerTest.php \
        tests/Unit/Management/ManagementTest.php
git commit -m "refactor: Publisher, Consumer, Management use session->nextFrame() (shared session frame buffer)"
```

---

## Chunk 2: Consumer Filter/Offset Wiring

### Task 4: TypeEncoder::encodeTimestamp + PerformativeEncoder filter map support

**Files:**
- Modify: `src/AMQP10/Protocol/TypeEncoder.php`
- Modify: `src/AMQP10/Protocol/PerformativeEncoder.php`
- Modify: `tests/Unit/Protocol/TypeEncoderTest.php`

**Context:** `ConsumerBuilder` accepts `offset()`, `filterSql()`, and `filterValues()`. These are stored and passed to `Consumer`, but never wired to the ATTACH source terminus. The fix has three parts: (1) add `TypeEncoder::encodeTimestamp()` for stream offset by timestamp, (2) extend `PerformativeEncoder::encodeSource()` to optionally include a pre-encoded filter map in the source terminus fields, (3) extend `PerformativeEncoder::attach()` to accept a `?string $filterMap` parameter.

The AMQP 1.0 source terminus (descriptor 0x28) is a list. Field 7 (zero-indexed) is the filter-set. We include fields 0–7, with fields 1–6 as null, when a filter is provided. When no filter, we keep the existing single-field list for compactness.

Filter encoding (based on RabbitMQ AMQP 1.0 client conventions):
- Stream offset key: `symbol("rabbitmq:stream-offset-spec")`
- Stream offset value: `described(symbol("rabbitmq:stream-offset-spec"), <offset_value>)` where offset_value is a symbol ("first"/"last"/"next"), ulong (absolute), or timestamp (ms since epoch)
- SQL filter key: `symbol("apache.org:selector-filter:string")`
- SQL filter value: `described(symbol("apache.org:selector-filter:string"), string(sql))`

- [ ] **Step 1: Write failing tests**

Add to `tests/Unit/Protocol/TypeEncoderTest.php`:
```php
public function test_encode_timestamp(): void
{
    $encoded = TypeEncoder::encodeTimestamp(1_700_000_000_000);
    // TypeCode::TIMESTAMP (0x83) + 8-byte big-endian int64
    $this->assertSame(0x83, ord($encoded[0]));
    $this->assertSame(9, strlen($encoded));  // 1 type byte + 8 data bytes

    // Decode back: unpack big-endian int64 as two uint32s
    $high = unpack('N', substr($encoded, 1, 4))[1];
    $low  = unpack('N', substr($encoded, 5, 4))[1];
    $decoded = ($high << 32) | $low;
    $this->assertSame(1_700_000_000_000, $decoded);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeEncoderTest.php --filter test_encode_timestamp -v
```
Expected: FAIL (method doesn't exist)

- [ ] **Step 3: Add `TypeEncoder::encodeTimestamp()`**

Add to `src/AMQP10/Protocol/TypeEncoder.php` after `encodeBinary()`:

```php
/**
 * Encode an AMQP 1.0 timestamp (signed int64, ms since Unix epoch).
 * TypeCode::TIMESTAMP (0x83) + 8 bytes big-endian.
 *
 * Note: pack('J', ...) is unsigned 64-bit big-endian. For positive timestamps
 * (post-1970 dates, which is the only realistic use case here), the bit pattern
 * is identical to signed int64. Pre-1970 timestamps (negative ms) are not supported.
 */
public static function encodeTimestamp(int $ms): string
{
    return pack('C', TypeCode::TIMESTAMP) . pack('J', $ms);
}
```

- [ ] **Step 4: Run TypeEncoder tests**

```bash
./vendor/bin/phpunit tests/Unit/Protocol/TypeEncoderTest.php -v
```
Expected: all PASS

- [ ] **Step 5: Update `PerformativeEncoder::encodeSource()` and `attach()`**

Change the private `encodeSource()` method signature and body in `src/AMQP10/Protocol/PerformativeEncoder.php`:

```php
private static function encodeSource(?string $address, ?string $filterMap = null): string
{
    if ($address === null) {
        return TypeEncoder::encodeNull();
    }
    if ($filterMap === null) {
        // Compact: list with address only (fields 1–10 are implicitly null)
        $fields = [TypeEncoder::encodeString($address)];
    } else {
        // Full source terminus with filter-set at field index 7:
        // [0]=address [1]=durable [2]=expiry-policy [3]=timeout
        // [4]=dynamic [5]=dynamic-node-props [6]=distribution-mode [7]=filter
        $fields = [
            TypeEncoder::encodeString($address), // field 0: address
            TypeEncoder::encodeNull(),            // field 1: durable
            TypeEncoder::encodeNull(),            // field 2: expiry-policy
            TypeEncoder::encodeNull(),            // field 3: timeout
            TypeEncoder::encodeNull(),            // field 4: dynamic
            TypeEncoder::encodeNull(),            // field 5: dynamic-node-props
            TypeEncoder::encodeNull(),            // field 6: distribution-mode
            $filterMap,                           // field 7: filter (pre-encoded AMQP map)
        ];
    }
    return TypeEncoder::encodeDescribed(
        TypeEncoder::encodeUlong(0x28), // source descriptor
        TypeEncoder::encodeList($fields),
    );
}
```

Add `?string $filterMap = null` parameter to `attach()`:

```php
public static function attach(
    int     $channel,
    string  $name,
    int     $handle,
    bool    $role,
    ?string $source               = null,
    ?string $target               = null,
    int     $sndSettleMode        = self::SND_UNSETTLED,
    int     $rcvSettleMode        = self::RCV_FIRST,
    ?array  $properties           = null,
    ?int    $initialDeliveryCount = null,
    ?string $filterMap            = null,  // ← new param
): string {
    $fields = [
        TypeEncoder::encodeString($name),
        TypeEncoder::encodeUint($handle),
        TypeEncoder::encodeBool($role),
        TypeEncoder::encodeUbyte($sndSettleMode),
        TypeEncoder::encodeUbyte($rcvSettleMode),
        self::encodeSource($source, $filterMap),  // ← pass filterMap
        self::encodeTarget($target),
        TypeEncoder::encodeNull(), // unsettled
        TypeEncoder::encodeNull(), // incomplete-unsettled
        $initialDeliveryCount !== null
            ? TypeEncoder::encodeUint($initialDeliveryCount)
            : TypeEncoder::encodeNull(),
        TypeEncoder::encodeNull(), // max-message-size
        TypeEncoder::encodeNull(), // offered-capabilities
        TypeEncoder::encodeNull(), // desired-capabilities
        $properties !== null
            ? TypeEncoder::encodeMap($properties)
            : TypeEncoder::encodeNull(),
    ];
    return FrameBuilder::amqp(channel: $channel, body: self::described(Descriptor::ATTACH, $fields));
}
```

- [ ] **Step 6: Run all unit tests to verify no regression**

```bash
./vendor/bin/phpunit 2>&1
```
Expected: all PASS

- [ ] **Step 7: Commit**

```bash
git add src/AMQP10/Protocol/TypeEncoder.php src/AMQP10/Protocol/PerformativeEncoder.php \
        tests/Unit/Protocol/TypeEncoderTest.php
git commit -m "feat: add TypeEncoder::encodeTimestamp(); PerformativeEncoder::attach() accepts filterMap for source terminus"
```

---

### Task 5: Wire Consumer filters through ReceiverLink to ATTACH

**Files:**
- Modify: `src/AMQP10/Connection/ReceiverLink.php`
- Modify: `src/AMQP10/Messaging/Consumer.php`
- Create: `tests/Unit/Connection/ReceiverLinkTest.php`

**Context:** Consumer stores `$offset`, `$filterSql`, and `$filterValues` but never uses them. We need to: (1) Consumer builds a pre-encoded filter map from its offset/filterSql, (2) Consumer passes it to ReceiverLink constructor, (3) ReceiverLink passes it to PerformativeEncoder::attach(). The filter map is built entirely in Consumer using TypeEncoder — no Messaging types bleed into the Connection layer.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Connection/ReceiverLinkTest.php`:

```php
<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ReceiverLinkTest extends TestCase
{
    private function makeSession(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        return [$mock, $session];
    }

    public function test_attach_sends_attach_with_receiver_role(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $link = new ReceiverLink($session, name: 'recv', source: '/queues/test');
        $link->attach();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames);
        $perf = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $perf['descriptor']);
        $this->assertTrue($perf['value'][2]); // role=true means receiver
    }

    public function test_attach_with_filter_map_includes_filter_in_sent_frame(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/streams/mystream', target: null,
        ));

        // Build a stream-offset filter map (as Consumer would)
        $filterMap = TypeEncoder::encodeMap([
            TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec') =>
                TypeEncoder::encodeDescribed(
                    TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec'),
                    TypeEncoder::encodeSymbol('first'),
                ),
        ]);

        $link = new ReceiverLink(
            $session,
            name:      'recv',
            source:    '/streams/mystream',
            filterMap: $filterMap,
        );
        $link->attach();

        // Verify the ATTACH frame was sent (does not throw = filter encoding was accepted)
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);

        $perf = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $perf['descriptor']);

        // Source is field index 5 in the ATTACH list
        $sourceEncoded = $perf['value'][5] ?? null;
        $this->assertNotNull($sourceEncoded, 'Source terminus must be present');
        // Source is a described type with descriptor 0x28
        $this->assertSame(0x28, $sourceEncoded['descriptor'] ?? null);
        // Source list field 7 is the filter-set — should not be null
        $filterField = $sourceEncoded['value'][7] ?? null;
        $this->assertNotNull($filterField, 'Filter map must be present in source terminus field 7');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Connection/ReceiverLinkTest.php -v
```
Expected: `test_attach_with_filter_map_includes_filter_in_sent_frame` FAIL (ReceiverLink has no filterMap param)

- [ ] **Step 3: Update `ReceiverLink.php` — add `?string $filterMap` constructor param**

Add parameter to constructor and pass to `attach()`:

```php
public function __construct(
    private readonly Session  $session,
    private readonly string   $name,
    private readonly string   $source,
    private readonly ?string  $target         = null,
    private readonly int      $initialCredit  = 10,
    private readonly bool     $managementLink = false,
    private readonly ?string  $filterMap      = null,   // ← new param
) {
    $this->handle = $session->allocateHandle();
}
```

Update `attach()` to pass `filterMap` to `PerformativeEncoder::attach()`:

```php
public function attach(): void
{
    $properties = $this->managementLink
        ? [TypeEncoder::encodeSymbol('paired') => TypeEncoder::encodeBool(true)]
        : null;

    $this->session->transport()->send(PerformativeEncoder::attach(
        channel:    $this->session->channel(),
        name:       $this->name,
        handle:     $this->handle,
        role:       PerformativeEncoder::ROLE_RECEIVER,
        source:     $this->source,
        target:     $this->target,
        properties: $properties,
        filterMap:  $this->filterMap,   // ← pass filter map
    ));
    $this->session->readFrameOfType(Descriptor::ATTACH);
    $this->attached = true;
    $this->grantCredit($this->initialCredit);
}
```

- [ ] **Step 4: Update `Consumer.php` — build filter map and pass to ReceiverLink**

**Verify Offset class shape first:** `src/AMQP10/Messaging/Offset.php` has two public readonly properties: `string $type` (values: "first", "last", "next", "offset", "timestamp") and `mixed $value` (null for named types, int for offset/timestamp). The code below uses these exactly.

Add private method `buildFilterMap()` and update the constructor to pass it to ReceiverLink:

```php
use AMQP10\Protocol\TypeEncoder;
```

Add `buildFilterMap()`:
```php
private function buildFilterMap(): ?string
{
    if ($this->offset === null && $this->filterSql === null) {
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
        $pairs[TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec')] =
            TypeEncoder::encodeDescribed(
                TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec'),
                $offsetValue,
            );
    }

    if ($this->filterSql !== null) {
        $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
            TypeEncoder::encodeDescribed(
                TypeEncoder::encodeSymbol('apache.org:selector-filter:string'),
                TypeEncoder::encodeString($this->filterSql),
            );
    }

    return TypeEncoder::encodeMap($pairs);
}
```

Update the constructor to pass filter map to ReceiverLink:

```php
public function __construct(
    private readonly Session  $session,
    private readonly string   $address,
    private readonly int      $credit       = 10,
    private readonly ?Offset  $offset       = null,
    private readonly ?string  $filterSql    = null,
    private readonly array    $filterValues = [],
) {
    $linkName   = 'receiver-' . bin2hex(random_bytes(4));
    $this->link = new ReceiverLink(
        $session,
        name:          $linkName,
        source:        $address,
        initialCredit: $credit,
        filterMap:     $this->buildFilterMap(),
    );
}
```

- [ ] **Step 5: Run unit tests**

```bash
./vendor/bin/phpunit 2>&1
```
Expected: all PASS

- [ ] **Step 6: Commit**

```bash
git add src/AMQP10/Connection/ReceiverLink.php src/AMQP10/Messaging/Consumer.php \
        tests/Unit/Connection/ReceiverLinkTest.php
git commit -m "feat: wire Consumer offset/SQL filters to ATTACH source terminus via ReceiverLink"
```

---

### Task 6: Integration test — stream offset filter against RabbitMQ

**Files:**
- Create: `tests/Integration/StreamFilterIntegrationTest.php`

**Context:** Verify the filter encoding actually works with RabbitMQ 4.x. RabbitMQ must be running with the AMQP 1.0 plugin enabled (Docker container `rabbitmq-amqp10-test` on port 5672). The test creates a stream queue, publishes messages, then consumes with an offset filter to verify only messages from the offset onward are received.

**Note on test design:** Stream queue requires `QueueType::STREAM`. The integration test checks that Consumer with `offset(Offset::first())` receives published messages. This verifies the filter encoding roundtrip works end-to-end with RabbitMQ.

- [ ] **Step 1: Create `tests/Integration/StreamFilterIntegrationTest.php`**

Only the `Offset::first()` test is included — the "last offset receives nothing" case would require polling/timeout and is deferred to a future test iteration.

The test sets `set_time_limit(15)` inside `run()` to guard against an infinite hang if the filter encoding is wrong and messages never arrive.

```php
<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

class StreamFilterIntegrationTest extends IntegrationTestCase
{
    private \AMQP10\Client\Client $client;
    private string $streamName = 'integ-stream-filter';

    protected function setUp(): void
    {
        $this->client = $this->newClient()->connect();
        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($this->streamName, QueueType::STREAM));
        $mgmt->close();
    }

    protected function tearDown(): void
    {
        if (!$this->client->isConnected()) {
            $this->client = $this->newClient()->connect();
        }
        $mgmt = $this->client->management();
        try { $mgmt->deleteQueue($this->streamName); } catch (\Throwable) {}
        $mgmt->close();
        $this->client->close();
    }

    public function test_consume_stream_from_first_offset(): void
    {
        $address = AddressHelper::streamAddress($this->streamName);

        // Publish two messages to the stream
        $publisher = $this->client->publish($address);
        $publisher->send(new Message('stream-msg-1'));
        $publisher->send(new Message('stream-msg-2'));
        $publisher->close();

        // Consume from the beginning using Offset::first() filter
        $received = [];
        $count    = 0;
        $client   = $this->client;

        // Guard: test fails (fatal) after 15 seconds if consumer never receives the expected messages.
        // This would indicate the filter encoding is wrong and RabbitMQ ignored the filter,
        // sending no messages or sending them to the wrong address.
        set_time_limit(15);

        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::first())
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 2) {
                    $client->close(); // stop the loop
                }
            })
            ->run();

        $this->assertCount(2, $received);
        $this->assertContains('stream-msg-1', $received);
        $this->assertContains('stream-msg-2', $received);
    }
}
```

**If this test fails with a connection error or filter-encoding issue:**
- Check RabbitMQ logs: `docker logs rabbitmq-amqp10-test | tail -20`
- The filter encoding used is: key = plain `symbol("rabbitmq:stream-offset-spec")`, value = `described(symbol("rabbitmq:stream-offset-spec"), symbol("first"))`. This matches the pattern used by the RabbitMQ .NET/Go AMQP 1.0 clients.
- If RabbitMQ rejects the filter, try removing the `described()` wrapper: use `TypeEncoder::encodeSymbol('first')` directly as the filter value in `Consumer::buildFilterMap()`.
- Reference: RabbitMQ AMQP 1.0 stream filtering uses the `rabbitmq:stream-offset-spec` symbol key. See RabbitMQ source or the Python/Java client implementations for exact wire format.

- [ ] **Step 2: Run integration tests**

Ensure RabbitMQ is running:
```bash
docker ps --filter name=rabbitmq-amqp10-test
```

```bash
./vendor/bin/phpunit -c phpunit-integration.xml -v 2>&1
```
Expected: all tests PASS including the new stream filter test

If the stream filter test fails with an error about the filter encoding, it means the wire format doesn't match what RabbitMQ expects. Check the error message and adjust the filter encoding in `Consumer::buildFilterMap()`. Common fixes:
- Try without the `described()` wrapper on the value: just `TypeEncoder::encodeSymbol('first')` directly
- Try with the filter key as a described type rather than a plain symbol

- [ ] **Step 3: Run all unit tests to confirm nothing is broken**

```bash
./vendor/bin/phpunit 2>&1
```
Expected: all unit tests PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/StreamFilterIntegrationTest.php
git commit -m "test: add integration test for stream offset filter (Offset::first())"
```

---

## Final Verification

After all tasks are complete:

- [ ] Run all unit tests: `./vendor/bin/phpunit` — expect 138+ tests PASS
- [ ] Run all integration tests: `./vendor/bin/phpunit -c phpunit-integration.xml` — expect 7+ tests PASS
- [ ] Update `memory/project_future_work.md` — remove the two completed items
- [ ] Update `memory/MEMORY.md` — note both deferred items are now complete

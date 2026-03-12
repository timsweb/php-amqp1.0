# Code Review Fixes for Deferred Work Implementation

**Date:** 2026-03-12
**Status:** Design Approved
**Related:** Implementation Plan `docs/superpowers/plans/2026-03-12-deferred-work.md`

## Overview

This design addresses code review feedback on the deferred work implementation that added AMQP 1.0 spec compliance features (session/link acknowledgment and consumer filter support). The fixes address 2 critical issues, 5 important issues, and 3 minor issues.

## Critical Issues

### Issue 1: Infinite Spin Loop in readFrameOfType()

**Location:** `Session.php:88–95`

**Problem:**
The `readFrameOfType()` method enters an infinite CPU-consuming spin loop when a hung broker repeatedly returns empty strings from non-blocking socket reads. The condition `!isConnected() && data === ''` doesn't help because the transport remains connected, just not sending data.

**Design:**
Add a consecutive empty read counter with threshold-based timeout:

1. Track `int $consecutiveEmptyReads = 0` in `readFrameOfType()`
2. Increment counter when `transport->read()` returns `''`
3. Reset counter on any non-empty read
4. Throw `RuntimeException` with message "Timeout awaiting frame with descriptor 0x{hex}" after 100 consecutive empty reads
5. Threshold chosen based on typical non-blocking socket behavior - 100 reads without data indicates hung peer

**Rationale:**
- Simple, explicit implementation
- Prevents production CPU exhaustion
- Threshold is configurable if needed
- Fails fast on hung connections rather than spinning indefinitely

### Issue 2: Quadratic Decode in readFrameOfType()

**Location:** `Session.php:77–84`

**Problem:**
Under pipelining scenarios (e.g., Management makes back-to-back `attach()` calls), `readFrameOfType()` re-decodes all buffered pending frames on every outer loop iteration, creating O(n²) cost.

**Design:**
Cache decoded descriptors alongside raw frames in the pending buffer:

1. Change `Session::$pendingFrames` from `array<string $rawFrame>` to `array{raw: string, descriptor: ?int}[]`
2. Decode descriptor immediately when frame enters buffer in `readFrameOfType()` and `nextFrame()`
3. Store decoded descriptor alongside raw frame
4. Use cached descriptor for filtering instead of re-decoding
5. Update filtering logic to check `$frame['descriptor']` instead of decoding

**Data Structure:**
```php
private array $pendingFrames = [];  // Each element: ['raw' => string, 'descriptor' => ?int]
```

**Rationale:**
- Decode once, read many
- Minimal memory overhead (one int per frame)
- Simple change to existing structure
- Eliminates quadratic behavior entirely

## Important Issues

### Issue 3: Double Decoding in Consumer

**Location:** `Consumer.php:87–113`

**Problem:**
`isTransferFrame()` and `handleTransfer()` both decode the same frame body. The first decode is wasted when the frame is a TRANSFER.

**Design:**
Refactor `isTransferFrame()` to `getFrameDescriptor()` returning `?int`:

```php
private function getFrameDescriptor(string $frame): ?int
{
    $body = FrameParser::extractBody($frame);
    $performative = (new TypeDecoder($body))->decode();
    return is_array($performative) ? ($performative['descriptor'] ?? null) : null;
}
```

Usage:
```php
if ($this->getFrameDescriptor($frame) === Descriptor::TRANSFER) {
    $this->handleTransfer($frame, $handler, $errorHandler);
}
```

**Note:** `handleTransfer()` still decodes, but this eliminates the explicit wasted decode. Further optimization (caching decoded result) can be done separately if profiling shows need.

### Issue 4: Missing Failure Test for ReceiverLink

**Location:** `tests/Unit/Connection/ReceiverLinkTest.php`

**Problem:**
`SenderLinkTest` has `test_attach_throws_when_server_does_not_respond()` but `ReceiverLinkTest` lacks equivalent coverage.

**Design:**
Add `test_attach_throws_when_server_does_not_respond()` to `ReceiverLinkTest`:

```php
public function test_attach_throws_when_server_does_not_respond(): void
{
    $mock = new TransportMock();
    $mock->connect('amqp://test');
    $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
    $session = new Session($mock, channel: 0);
    $session->begin();

    $link = new ReceiverLink($session, name: 'recv', source: '/queues/test');

    $this->expectException(\RuntimeException::class);
    $link->attach();
}
```

**Rationale:**
- Ensures `readFrameOfType()` properly throws when ATTACH response missing
- Mirrors existing SenderLink test pattern
- Important coverage for spec compliance feature

### Issue 5: Integration Test Doesn't Test Stream Filters

**Location:** `tests/Integration/StreamFilterIntegrationTest.php`

**Problem:**
`test_consume_from_queue()` uses `QueueType::CLASSIC` with no offset/filterSql, testing plain consumption rather than the new filter functionality.

**Design:**
Rename and expand test coverage:

1. Rename existing test to `test_consume_from_classic_queue()`
2. Add new test `test_consume_from_stream_with_offset()`:
   - Create stream queue with `QueueType::STREAM`
   - Publish 10 messages
   - Consume with `Offset::first()` (or specific offset)
   - Verify only messages from offset are received
3. Add skip marker if RabbitMQ stream plugin unavailable:
   ```php
   if (!$this->hasStreamPlugin()) {
       $this->markTestSkipped('RabbitMQ stream plugin not available');
   }
   ```

**Rationale:**
- Actually tests the new feature
- Clear separation of classic vs stream behavior
- Graceful degradation when plugin unavailable

### Issue 6: Magic Numbers for Source/Target Descriptors

**Location:** `PerformativeEncoder.php:225, 237`

**Problem:**
`encodeSource()` and `encodeTarget()` use magic numbers `0x28` and `0x29` instead of `Descriptor` constants.

**Design:**
Add constants to `Descriptor` class:

```php
// Link terminus descriptors
public const SOURCE = 0x28;
public const TARGET = 0x29;
```

Replace in `PerformativeEncoder`:
```php
// Before:
TypeEncoder::encodeUlong(0x28), // source descriptor

// After:
TypeEncoder::encodeUlong(Descriptor::SOURCE),
```

**Rationale:**
- Consistent with all other descriptors
- Self-documenting code
- Prevents copy-paste errors

### Issue 7: Dead `$filterValues` Parameter

**Location:** `Consumer.php`, `ConsumerBuilder.php`

**Problem:**
`$filterValues` parameter accepted by `Consumer` and `ConsumerBuilder` but never used by `buildFilterMap()`.

**Design:**
Remove unused parameter:

1. Remove `$filterValues` from `Consumer::__construct()`
2. Remove `filterValues()` method from `ConsumerBuilder`
3. Update `ConsumerBuilder::run()` to not pass it to `Consumer` constructor

**Rationale:**
- Eliminates misleading API surface
- YAGNI - never wired, not needed now
- Can be added later with proper implementation if needed

## Minor Issues

### Issue 8: nextFrame() Conflation (No Change)

**Problem:** `nextFrame()` returns `null` on both "no data yet" and "connection closed".

**Decision:** No change needed. The consumer loop pattern expects to exit when no frames arrive, which matches current behavior. The conflation is acceptable for this use case.

### Issue 9: Missing Docblock for $filterMap

**Location:** `ReceiverLink.php:25`

**Design:**
Add docblock parameter documentation:

```php
* @param string|null $filterMap Pre-encoded AMQP binary filter map for source terminus
```

**Rationale:**
- Clarifies it expects pre-encoded AMQP binary, not PHP array
- Important distinction since other methods use PHP arrays

### Issue 10: Session::end() Asymmetry Comment

**Location:** `Session.php:35`

**Design:**
Add comment above `end()` method:

```php
/**
 * Note: Does NOT await server END response, unlike begin() which awaits BEGIN.
 * This asymmetry exists because closing a session is best-effort in common patterns.
 */
```

**Rationale:**
- Prevents future confusion about asymmetry
- Documents design intent
- Helps maintainers understand the choice

## Implementation Order

1. Fix Issue #2 (quadratic decode) - structural change to pendingFrames
2. Fix Issue #1 (infinite spin loop) - uses updated pendingFrames structure
3. Fix Issue #6 (magic numbers) - simple constant addition
4. Fix Issue #3 (double decoding) - Consumer refactoring
5. Fix Issue #4 (missing test) - test addition
6. Fix Issue #5 (integration test) - test expansion
7. Fix Issue #7 (dead parameter) - API cleanup
8. Fix Issue #9 (docblock) - documentation
9. Fix Issue #10 (asymmetry comment) - documentation

## Testing Strategy

- Unit tests for all new code paths
- Ensure existing 146 unit tests pass
- Ensure existing 8 integration tests pass
- Verify stream filter test runs or skips appropriately

## Backward Compatibility

All changes are internal implementation details:
- No public API changes (except removing dead `$filterValues`)
- Behavior changes only in error cases (timeout throw instead of infinite spin)
- No breaking changes to existing working code

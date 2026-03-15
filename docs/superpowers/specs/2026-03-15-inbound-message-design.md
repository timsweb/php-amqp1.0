# InboundMessage — Unified Consumer Interface

**Date:** 2026-03-15
**Status:** Approved

## Problem

The consumer API has two friction points:

1. **Inconsistency.** `run()` unpacks the delivery into two handler parameters `(Message $message, DeliveryContext $ctx)`, while `receive()` returns a `Delivery` wrapper requiring `$delivery->message()->body()` and `$delivery->context()->accept()`.

2. **Unintuitive settlement.** Calling `$ctx->accept()` on a separate context object is awkward — accepting a delivery is logically an operation on the received message itself.

Other AMQP clients (go-amqp, bunny, laravel-queue) use a single unified object where message data and settlement methods live together.

## Design

### New class: `InboundMessage`

`src/AMQP10/Messaging/InboundMessage.php`

Holds references to `Message` and `DeliveryContext` internally. Exposes a unified public interface:

**Message data accessors** (delegated to `Message`):
- `body(): string`
- `subject(): ?string`
- `durable(): bool`
- `ttl(): int`
- `priority(): int`
- `property(string $key): mixed`
- `applicationProperty(string $key): mixed`
- `annotation(string $key): mixed`
- `properties(): array`
- `applicationProperties(): array`
- `annotations(): array`
- `message(): Message` — escape hatch for callers that need the raw `Message`

**Settlement methods** (delegated to `DeliveryContext`):
- `accept(): void`
- `reject(): void`
- `release(): void`
- `modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void`

The `with*` builder methods from `Message` are not delegated — mutating a received message for re-sending is done by constructing a new outbound `Message`.

### Consumer changes

- `Consumer::receive()` return type: `?Delivery` → `?InboundMessage`
- `Consumer::run()` handler signature: `(Message $message, DeliveryContext $ctx)` → `(InboundMessage $msg)`
- `Consumer::handleTransferFrame()` (private): returns `?InboundMessage` instead of `?Delivery`

### ConsumerBuilder

`ConsumerBuilder::handle(Closure $handler)` stores the handler closure; the method signature is unchanged. The expected handler signature it documents changes to `(InboundMessage $msg)`. `ConsumerBuilder::run()` delegates to `Consumer::run()`, so no further changes are needed there.

### Removed

- `src/AMQP10/Messaging/Delivery.php` — deleted. `InboundMessage` replaces it entirely in the public API. `DeliveryContext` remains as an internal implementation detail.

### Breaking changes

This is a breaking API change. Pre-1.0, so accepted. All examples updated to the new signature.

## Usage

```php
// run() loop
$consumer->run(function (InboundMessage $msg) {
    echo $msg->body();
    $msg->accept();
});

// one-shot
$msg = $consumer->receive();
if ($msg !== null) {
    echo $msg->body();
    $msg->accept();
}

// batched
while ($msg = $consumer->receive()) {
    $batch[] = $msg;
}
foreach ($batch as $msg) {
    echo $msg->body();
    $msg->accept();
}

// escape hatch
$consumer->run(function (InboundMessage $msg) use ($processor) {
    $processor->handle($msg->message()); // passes raw Message where Message is type-hinted
    $msg->accept();
});
```

## Testing

- `tests/Unit/Messaging/ConsumerTest.php` — update assertions from `Delivery` to `InboundMessage`; update handler closures to accept `InboundMessage`
- `tests/Unit/Messaging/ConsumerBuilderTest.php` — update handler closure signatures
- `tests/Unit/Messaging/DeliveryContextTest.php` — rename to `InboundMessageTest.php`; exercise settlement (`accept`, `reject`, `release`, `modify`) through `InboundMessage` rather than `DeliveryContext` directly, since `DeliveryContext` is now internal
- Delete `tests/Unit/Messaging/DeliveryTest.php` if it exists (the `Delivery` class is removed)

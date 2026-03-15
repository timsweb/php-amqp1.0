# Roadmap

Ideas and future work that have come up during development but are out of scope for current changes. Each item includes the context in which it was identified.

---

## Dynamic Credit Control

**Context:** Identified during consumer stop control design (2026-03-15).

AMQP 1.0 link credit is a flow-control primitive — the receiver can send a FLOW frame at any time to grant more credit, reduce it, or set it to zero to pause delivery. The builder's `credit()` setting controls the *initial grant and auto-replenishment window*; adjusting credit at runtime is a separate concept that would allow consumers to apply backpressure dynamically.

Potential API:

```php
$consumer->pauseDelivery();       // send FLOW with credit=0
$consumer->resumeDelivery();      // send FLOW restoring original credit
$consumer->grantCredit(int $n);   // explicit one-time grant
```

Builds naturally on the consumer reference pattern introduced in the stop control work.

---

Openswool Transport/support. 

---

Pacakge for connection pooling/shring in Laravel Octane.

---

## Unified Consumer Message Interface

**Context:** Developer experience improvement discussion (2026-03-15).

Currently, consumers receive a `Delivery` object that separates message data (`Delivery::message()`) from delivery operations (`Delivery::context()`). This requires handlers to accept two parameters or unpack them:

```php
// Current
$client->consume($queue)->run(function (Message $msg, DeliveryContext $ctx) {
    echo $msg->body();
    $ctx->accept();
});
```

Proposed: Create a `ReceivedMessage` class that composes `Message` and `DeliveryContext` into a single interface with convenience methods:

```php
// Improved
$client->consume($queue)->run(function (ReceivedMessage $msg) {
    echo $msg->body();
    $msg->accept(); // delegates to internal DeliveryContext
});
```

**Implementation notes:**
- Create `src/AMQP10/Messaging/ReceivedMessage.php`
- Holds references to both `Message` and `DeliveryContext`
- Delegates property accessors (`body()`, `subject()`, `properties()`, etc.) to `Message`
- Delegates delivery methods (`accept()`, `reject()`, `release()`, `modify()`) to `DeliveryContext`
- Update `Consumer::handleTransferFrame()` to return `Delivery` containing `ReceivedMessage` instead of separate `Message` + `DeliveryContext`
- Keep `Delivery` wrapper for future extensibility (metadata, links, etc.)
- Existing `Message` class unchanged (still used for sending)

**Trade-offs:**
- ✅ Simpler handler signatures, fewer parameters
- ✅ More intuitive — acking is an operation on the received message
- ✅ Aligns with other AMQP clients (e.g., `go-amqp`, `laravel-queue`)
- ⚠️ Adds another class to maintain
- ⚠️ Slightly blurs separation between "message data" and "delivery state" (internal separation preserved) 
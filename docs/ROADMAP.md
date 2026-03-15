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

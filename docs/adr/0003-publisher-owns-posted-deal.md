# 3. The publisher owns the PostedDeal write

Date: 2026-06-01
Status: Accepted

## Context

A successful publish must record a `PostedDeal` row, because the Already-Posted
Guard reads it back (via the `RecordedPriceHistory` seam) to suppress that ASIN in
later cycles. The `ChannelPublisher::publish()` seam returns `void`, so a failed
send must signal failure by throwing.

The question is where "delivered ⇒ recorded" lives. Options considered:

1. The `WahaChannelPublisher` writes `PostedDeal` itself on a 2xx and throws on
   failure; the controller wraps `publish()` in try/catch.
2. The controller writes `PostedDeal` after `publish()` returns; the publisher is
   pure HTTP.
3. Drop `PostedDeal`, use `FoundDeal.publishRequestedAt` as the only "posted"
   marker.

Option 3 breaks the de-dup contract — the Guard cannot suppress what it cannot
read. Option 2 splits "delivered" and "recorded" across classes, so any future
caller (notably the deferred cron publisher) could deliver without recording.

## Decision

`WahaChannelPublisher.publish()` owns the invariant:

- On a 2xx from `/send`, it persists `PostedDeal(asin, snapshotPriceCents, now)`.
- On any failure (non-2xx, transport error, missing `affiliateUrl`, or a null
  `snapshotPriceCents` that `PostedDeal` cannot accept), it throws and persists
  nothing.

The controller does `try { publish(); markPublishRequested(now); flush() }
catch { flash error }` — so `publishRequestedAt` is set only after a delivery is
recorded, and a failure leaves the row clean and the Publish button clickable to
retry.

The publisher depends on Doctrine. This is allowed: the publisher is app-side
(`apps/pipeline`), and only the gateway is kept Doctrine-free.

## Consequences

- "Delivered" and "recorded" are atomic from any caller's view — the manual
  controller today and the cron path tomorrow both get the `PostedDeal` write for
  free, with no chance of drifting apart.
- The Already-Posted Guard's de-dup contract holds: a published ASIN is suppressed
  next cycle.
- The publisher couples to Doctrine and the `PostedDeal` entity, so it cannot move
  into `packages/shared` — it stays an `apps/pipeline` implementation behind the
  shared seam.
- `affiliateUrl` is the single product-level publishability gate (enforced in the
  publisher and mirrored in the template); the null-price throw is a defensive
  backstop, since price co-occurs with the affiliate URL in the Live Snapshot.

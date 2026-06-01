# WhatsApp Service

## Problem

The pipeline funnels deals all the way to *record* but cannot publish them. The
`ChannelPublisher` seam exists and the review page has a Publish button, but the
only implementation is `NullChannelPublisher`, which just logs — no message ever
reaches WhatsApp. The publishing capability was validated in a separate
TypeScript/Next.js prototype (`whatsapp-announcer`) that wraps WAHA (a Dockerized
WhatsApp-Web HTTP bridge), but that prototype lives outside this PHP monorepo and
nothing in `deal-promoter` can drive it. Until a real publisher fills the seam,
the affiliate value chain has no last mile: deals are found and recorded but
never earn.

**Confidence:** data-backed
**Sources:** the prototype proved QR pairing + channel send end-to-end against
WAHA; a plain Amazon link confirmed to render a preview card in a WhatsApp
channel (product spec). The seam, `PublishableDeal`, `PostedDeal`, and the
commented-out compose stubs are already in the repo, carved for exactly this.

## Solution

Port the prototype into a new standalone Symfony app, `apps/whatsapp-service`,
and wire a real publisher into the pipeline behind the existing seam.

The service is a **pure WhatsApp gateway**: it knows nothing about deals or
Postgres. It is a 1:1 port of the prototype — QR pairing UI, session lifecycle,
channel list, and a manual send form — rendered with Twig and a sprinkle of
vanilla `fetch` JS (no build step, matching the pipeline app). All WAHA calls go
through one `WahaClient` class (the port of `waha.ts`) that adds the WAHA
`X-Api-Key`.

The pipeline stays the brain. A new `WahaChannelPublisher` implements the
`ChannelPublisher` interface and calls the service over HTTP. The two apps meet
at a clear trust boundary:

```
pipeline  → POST /send    (JSON, X-Internal-Key gated) ─┐
human form → POST /ui/send (open, host-bound)           ─┴→ WahaClient::sendText() → WAHA → channel
pairing UI → /, /session*, /channels (open, host-bound)
```

The machine endpoint that posts to a real channel is gated by a shared secret;
the single-user pairing/test UI stays open but is only published on a local host
port. Both the gated `/send` and the open `/ui/send` route through the same
in-process `WahaClient`, so the WAHA key never reaches a browser.

The target channel is pipeline config (`WHATSAPP_CHANNEL_ID`), sent as `chatId`
in the `/send` body, keeping the service stateless (it still enforces the
`@newsletter` channels-only guard). On a 2xx send, `WahaChannelPublisher` writes
the `PostedDeal` row itself, then the controller marks `publishRequestedAt`; on
any failure it throws and nothing is persisted, so the button stays clickable to
retry. The `PostedDeal` write closes the loop — `AlreadyPostedGuard` reads it
back and suppresses that ASIN next cycle.

## Scope

### In scope

- As the operator, I can pair a WhatsApp account by scanning a QR code in the
  service UI, so the gateway can post on the account's behalf.
- As the operator, I can see live session status (STOPPED → SCAN_QR_CODE →
  WORKING) and log out / re-pair from the UI.
- As the operator, I can send an arbitrary plain-text message to an owned
  `@newsletter` channel from a manual form, to smoke-test the gateway without the
  pipeline.
- As the pipeline, I can `POST /send` a `{chatId, text}` to the service with the
  internal key and have WAHA deliver it to the configured channel.
- As the operator, I can click Publish on a recorded deal in the review page and
  have a real WhatsApp message land in the channel.
- As the system, when a send succeeds I record a `PostedDeal` row so the
  Already-Posted Guard suppresses that ASIN in later cycles.
- As the operator, when a send fails (session down, WAHA error, missing
  affiliate link) I see the error on the review page and nothing is recorded, so
  I can retry.

### Out of scope

- Paced auto-publish in `app:run-cycle` (cap per cycle, spacing, hourly ceiling,
  best-deals-first) — the seam is identical, this is additive later.
- An explicit session-WORKING pre-check before sending, and the operator alert on
  a dropped session — load-bearing only for the unattended cron path.
- The discount/attestation Deal Gate (what is "truly a deal" worth publishing) —
  still an open product decision upstream of this.
- Affiliate-disclosure copy and "% off" claims in the message — exact wording is
  an unresearched legal/ToS item.
- Multi-channel routing (category → channel) — one channel now, modeled as config.
- Media, attachments, polls, reactions, voice — text only, channels only.
- WAHA ban-risk hardening / WAHA Plus evaluation.
- Auth on the pairing/test UI (single-user, host-bound).

## Success Criteria

- Clicking Publish on a recorded deal with an affiliate URL delivers a
  plain-text message to the configured `@newsletter` channel, and the link
  renders a preview card.
- The delivered message reads, untruncated, German format:
  `{title}` / `12,99 €` / blank line / affiliate URL.
- A successful publish writes exactly one `PostedDeal(asin, snapshotPriceCents,
  postedAt)` row and sets `publishRequestedAt`; a failed publish writes neither.
- After a deal is published, the same ASIN is suppressed by the Already-Posted
  Guard on the next cycle.
- A deal with no `affiliateUrl` shows no Publish button, and a direct
  `POST /publish/{id}` for it is rejected without delivering or recording.
- `POST /send` without the `X-Internal-Key` is refused; the manual UI send still
  works without it.
- Swapping `NullChannelPublisher` → `WahaChannelPublisher` is the only pipeline
  wiring change (one `services.yaml` alias); controller and template need no edit
  beyond the affiliate-URL guard and error flash.

## Constraints

- PHP 8.4+ / Symfony 8, consistent with `apps/pipeline`. The service depends on
  neither `packages/shared` nor Doctrine — it is a standalone gateway.
- Channels only (`chatId` ends in `@newsletter`) and text only, enforced
  server-side in the service even though the pipeline already targets one channel.
- The WAHA `X-Api-Key` is read only by the service's `WahaClient` and must never
  reach a browser; the `X-Internal-Key` likewise stays server-to-server.
- All money is integer euro-cents end to end; the message formats
  `snapshotPriceCents / 100` as German `12,99 €`.
- Reuse the prototype's validated runtime: `devlikeapro/waha:chrome`, a
  `.sessions` volume for pairing persistence, session name `default`.
- `.env.example` only is edited; real `.env` is maintained by hand.

## Open Questions / Risks

- **Trust-boundary split** (gated machine `/send` vs open host-bound UI, both
  behind one in-process `WahaClient`) is an architectural decision worth an ADR
  via `domain-docs`.
- **Publisher owns the `PostedDeal` write** (the "delivered ⇒ recorded"
  invariant lives in `WahaChannelPublisher`, not the controller) — also
  ADR-worthy, as a future cron publisher must honor it too.
- WAHA session-drop frequency and WhatsApp channel rate limits are unmeasured;
  the manual flow surfaces failures to a human, but the deferred cron path will
  need the pre-check + alert before it can run unattended.
- `snapshotPriceCents` is nullable but `PostedDeal` requires a non-null int. In
  practice price co-occurs with `affiliateUrl` (same Live Snapshot), so the
  affiliate-URL gate covers it; a defensive throw on null price remains.

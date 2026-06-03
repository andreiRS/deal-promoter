# Opt-in High-Res Preview Deal Card

## Problem

A Channel deal card's strongest "this is a real deal" signal is the product photo,
but today it renders as a small, low-detail **inline thumbnail** beside the title.
The whatsmeow Engine (ADR 0005) embeds the image directly in the message bytes, and
WhatsApp clients shrink an inline thumbnail to roughly 100×48 px — so the photo is
low-detail by design. Competing deal channels show a large, full-width product image
at the top of the card, which reads as more credible and catches the eye in a feed.
We want that large card for our posts, while keeping the current behaviour as the
safe default.

**Confidence:** anecdotal
**Sources:** side-by-side screenshots of our card vs. a competing channel's large-image
card; whatsmeow research confirming the large card is driven by a separate
*uploaded* thumbnail mechanism (`ThumbnailDirectPath`/`ThumbnailSHA256` + declared
dimensions), not by the inline `JPEGThumbnail` — captured in ADR 0006.

## Solution

Add an **opt-in, per-send** high-res path to the engine, selected by a new
`preview.highRes` boolean on the `/send` JSON (nested under `preview`, default
`false`). The flag threads pipeline (PHP) → whatsapp-service gateway (PHP) →
whatsmeow-engine (Go) with no other contract change. `highRes:false` is today's
inline-only behaviour, byte-for-byte untouched, so every existing test stays green
and callers opt in explicitly.

On `highRes:true`, the engine fetches the source image once and produces **both**
Preview Thumbnails:

- the existing ~256 px inline `JPEGThumbnail`, kept as a fallback, and
- a larger derivative — source resized to **800 px longest side, aspect preserved,
  no padding**, JPEG q≈80 — uploaded to WhatsApp's media servers via
  `Client.UploadNewsletter(ctx, jpeg, whatsmeow.MediaLinkThumbnail)`.

The upload response maps onto the `ExtendedTextMessage`: `DirectPath →
ThumbnailDirectPath`, `FileSHA256 → ThumbnailSHA256`, with `ThumbnailWidth`/
`ThumbnailHeight` set to the **actual** resized dimensions, and the send carries
`SendRequestExtra{MediaHandle: resp.Handle}`. `PreviewType` stays `IMAGE`, matching
the working low-res path. `UploadNewsletter` (not the encrypted `Upload`) is
mandatory because the channel is a newsletter and newsletter media is unencrypted —
so `MediaKey`/`FileEncSHA256` come back empty by design, not as a bug. Amazon images
are square on white, so preserving aspect renders a square large card matching the
target look; no 1.91:1 letterboxing is added.

Failure degrades in tiers and **never fails the send**, consistent with ADR 0005:
fetch fails → no image; fetch succeeds but upload fails → small inline card; a
warning is logged at each downgrade. Internally, the upload is a new injected
collaborator (`uploadThumbnail`) alongside the existing `fetchThumbnail`/`sendMessage`
seams, and the send path threads the optional `MediaHandle` through — keeping the
high-res logic unit-testable without a live whatsmeow client.

## Scope

### In scope

- As a caller (pipeline), I can set `preview.highRes: true` on a `/send` request so
  a deal posts with a large, full-width product image atop the card.
- As a caller, when I omit `highRes` or set it `false`, I get exactly today's
  inline-only card with no behavioural or payload change.
- As the engine, on a high-res send I produce both an inline fallback thumbnail and
  an uploaded 800 px high-res thumbnail from a single source fetch, and reference the
  uploaded one on the message with its real dimensions and media handle.
- As the engine, I degrade in tiers and still post: no image if the fetch fails, the
  small inline card if only the upload fails, logging a warning at each downgrade.
- As a maintainer, I can unit-test the high-res path (upload success, upload failure
  fallback, fetch failure) through an injected `uploadThumbnail` seam without a real
  WhatsApp connection.

### Out of scope

- Changing the card's description text, title, or anything rating-related — image
  size only.
- Caching or de-duplicating uploads across sends; each high-res send fetches and
  uploads fresh.
- An explicit byte-size guard — 800 px @ q≈80 lands well under WhatsApp's practical
  ceiling on its own.
- Making the high-res path the default, or any global/env switch — it is strictly
  per-send and default-off.
- 1.91:1 letterboxing/padding, or any non-aspect-preserving resize.
- Hand-editing the real `.env` (only `.env.example`/templates if anything is needed).

## Success Criteria

- A `/send` with `preview.highRes: true` to the IronApiTest channel renders a large,
  full-width product image at the top of the card (manual live check).
- A `/send` with `highRes` omitted or `false` produces the current inline card,
  unchanged; all existing engine, gateway, and pipeline tests stay green.
- With `highRes: true` and a forced upload failure, the send still succeeds and the
  card shows the small inline thumbnail; a warning is logged.
- With `highRes: true` and a forced fetch failure, the send still succeeds with no
  image; a warning is logged.
- Engine unit tests cover the three high-res outcomes (upload ok, upload-fail
  fallback, fetch-fail) via the injected upload seam.

## Constraints

- Channel is a WhatsApp newsletter → uploads must use `UploadNewsletter` with
  `whatsmeow.MediaLinkThumbnail`; empty `MediaKey`/`FileEncSHA256` is correct, not a
  defect. The encrypted `Upload` would yield a broken channel preview.
- The gateway/engine trust boundary (ADR 0001/0002) and the "delivered ⇒ recorded"
  invariant are unchanged; the only wire change is the additive `preview.highRes`
  bool.
- `canonicalUrl` no longer exists in the current waE2E proto and is used by neither
  path.

## Open Questions / Risks

- **`PreviewType: IMAGE` renders blind until first live send.** The large-vs-small
  card is triggered by the uploaded thumbnail + its declared size; we set `IMAGE` on
  faith it matches the low-res path's behaviour, while the one confirmed-working
  whatsmeow channel example used `NONE`. Verify on the first live send to
  IronApiTest; if the card renders small despite the upload, flip the high-res path
  to `NONE` (a one-line change). Recorded in ADR 0006 as the single eyeball item.
- Each high-res send adds one media upload to WhatsApp's servers — a second external
  call and failure point per opted-in post. Mitigated by the tiered fallback, but
  sustained upload failures would mean small-card posts.

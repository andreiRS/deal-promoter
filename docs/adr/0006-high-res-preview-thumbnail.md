# 6. Opt-in high-res preview thumbnail via uploaded channel media

Date: 2026-06-03
Status: Accepted

## Context

ADR 0005 had the [whatsmeow Engine](../../GLOSSARY.md#whatsmeow-engine) hand-build
its own link-preview card with an **inline `JPEGThumbnail`** — a small image
embedded in the message bytes. That unblocked the free tier, but the inline path
is low-detail by design: WhatsApp clients shrink an inline thumbnail to roughly
100×48 px and render it as a compact image beside the title. Competing deal
channels show a large, full-width product photo at the top of the card, which
reads as more credible and eye-catching. We want that large card for our posts.

Research into `go.mau.fi/whatsmeow` found that the large card is not a different
message "type" — it is driven by a **second, separate thumbnail mechanism**:

- The **inline** `JPEGThumbnail` is always shrunk and always small.
- A thumbnail **uploaded** to WhatsApp's media servers and referenced by the
  message (`ThumbnailDirectPath` + `ThumbnailSHA256` + declared
  `ThumbnailWidth`/`ThumbnailHeight`) is downloaded and rendered full-size. No
  documented hard max dimension applies to it; ~800 px wide is the proven sweet
  spot and width ≥300 px is enough to make the client draw the large card.

Three options were considered:

1. **Replace the inline thumbnail with the uploaded one** for every post. Drops
   the fallback and silently changes existing behaviour and every current test;
   a single transient upload failure would degrade the card for all sends.
2. **Switch to high-res globally behind an env flag.** One setting, but no
   per-post control and the same all-or-nothing failure surface.
3. **Add an opt-in, per-send high-res path that keeps the inline thumbnail as a
   fallback.** The caller decides per message; the existing low-res path is
   untouched and stays the default.

## Decision

Add an **opt-in, per-send** high-res preview path to the engine, selected by a new
`preview.highRes` boolean on the `/send` JSON (nested under `preview`, default
`false`), threaded pipeline → [Gateway](../../GLOSSARY.md#gateway) → engine with no
other contract change. `false` keeps ADR 0005's inline-only behaviour exactly.

When `highRes` is `true`, the engine produces **both** [Preview
Thumbnails](../../GLOSSARY.md#preview-thumbnail) from one fetched source image:

- the existing ~256 px inline `JPEGThumbnail` (kept as a fallback), and
- a larger derivative — source resized to **800 px longest side, aspect
  preserved, no padding**, JPEG q≈80 — uploaded to WhatsApp via
  `Client.UploadNewsletter(ctx, jpeg, whatsmeow.MediaLinkThumbnail)`. The upload
  response maps onto the message as `DirectPath → ThumbnailDirectPath`,
  `FileSHA256 → ThumbnailSHA256`, with `ThumbnailWidth`/`ThumbnailHeight` set to
  the **actual** resized dimensions, and the send carries
  `SendRequestExtra{MediaHandle: resp.Handle}`.

`UploadNewsletter` (not the encrypted `Upload`) is mandatory because our
[Channel](../../GLOSSARY.md#channel) is a newsletter and newsletter media is
**unencrypted** — so `MediaKey`/`FileEncSHA256` come back empty **by design**, not
as a bug, and the regular encrypted upload would yield a broken channel preview.
Amazon's product images are square on white, so preserving aspect renders a square
large card (matching the target look); no 1.91:1 letterboxing is added.

Failure degrades in tiers and **never fails the send**, consistent with ADR 0005:

- source fetch fails → post the card with no image;
- fetch succeeds but the upload fails → fall back to the small inline card;
- a warning is logged at each downgrade.

`PreviewType` stays `IMAGE` on the high-res path, matching the working low-res
path and the fact that we are sending an image. This is the one element that
renders blind until a live send — see Consequences.

Out of scope: changing the card's description text, caching/de-duping uploads
across sends, and an explicit byte-size guard (800 px @ q≈80 lands well under
WhatsApp's practical ceiling on its own).

## Consequences

- Channel cards can show a large, full-width product photo per the goal, while the
  default path and every existing test stay unchanged — high-res is strictly
  additive and opt-in.
- Keeping the inline thumbnail as a fallback means even a failed upload still shows
  *an* image, preserving ADR 0005's degrade-don't-block guarantee. The cost is one
  extra resize/encode and one media upload per high-res send — a second external
  call and failure point that the tiered fallback absorbs.
- **Open verification risk:** the large-vs-small card is triggered by the uploaded
  thumbnail and its declared size, and we set `PreviewType: IMAGE` on faith that it
  matches the low-res path's behaviour. A whatsmeow channel example that is
  confirmed working instead used `PreviewType: NONE`. The card renders blind until
  the **first live send to the IronApiTest channel**; if it renders small despite
  the upload, switching the high-res path to `NONE` is a one-line change. This is
  the single thing to eyeball on first live send.
- ADR 0005 still holds — the engine, its graceful degradation, and the keyless
  gateway-only boundary are unchanged; this only adds a second thumbnail path
  behind a default-off flag. `canonicalUrl`, referenced in ADR 0005, no longer
  exists in the current waE2E proto and is not used by either path.
- Ban risk is unchanged: still behavioural (send volume/velocity), not
  request-agent. The high-res path adds one media upload to WhatsApp's own servers
  per opted-in post.

# whatsmeow Engine with Custom Link-Preview Cards

## Problem

Channel posts go out as plain text with the price, a sale emoji, and the affiliate link. WhatsApp renders these as a link preview card, but on the free WAHA **Core** tier the **preview image is blank**: WEBJS fetches a valid thumbnail that the WhatsApp client renders blank in a channel, GOWS never fetches one, and every image-bearing WAHA endpoint (`sendImage`, `send/link-custom-preview`) returns `422 — available only in Plus version`. The product image is the single strongest "this is a real deal" signal a post can carry, and competing deal channels show it. Paying for WAHA Plus solely to unblock previews is not a sound trade for a tool that only ever needs text posts with a working card, and even Plus would force a move off WEBJS plus a re-pair.

The root cause is that WAHA auto-generates the preview by fetching the affiliate (Amazon) page server-side, and Amazon's captcha blocks that fetch (WAHA issue #596). The fix is to stop auto-generating previews and instead supply our own preview data, which the underlying WhatsApp library supports for free, the captcha-gated page fetch disappears, and we already hold every field the card needs (`title`, `image_url`) per deal.

**Confidence:** data-backed
**Sources:** Blank-image behaviour and the `422` Plus gating reproduced live against the running WAHA container. WAHA issue #596 documents the Amazon-captcha root cause and that `send/link-custom-preview` is Plus-only and unsupported on WEBJS. whatsmeow's `ExtendedTextMessage` proto exposes `title`/`description`/`matchedText`/`canonicalUrl`/`JPEGThumbnail`/`previewType` directly. That a custom-thumbnail preview renders its image inside a WhatsApp channel was confirmed by the operator's own research.

## Solution

Replace the WAHA container with a self-hosted **whatsmeow engine** (a new Go service at `apps/whatsmeow-engine`), built on the MIT-licensed `go.mau.fi/whatsmeow` library that GOWS wraps. whatsmeow does not auto-generate link previews; instead we hand-build an `ExtendedTextMessage` with `matchedText`/`canonicalUrl` set to the affiliate URL, `title` set to the product title, and a small `JPEGThumbnail` the engine produces by fetching the deal's `image_url` itself. Because the engine supplies the thumbnail, Amazon's product page is never fetched for a preview, so the captcha that breaks WAHA's path is irrelevant; the only Amazon hit is a plain GET to the image CDN, with a request agent we fully control.

The PHP `whatsapp-service` stays in place as the gateway and trust boundary. The whatsmeow engine absorbs every WAHA-ism so the gateway and its pairing UI need no behavioural change: the engine returns the **same** status vocabulary (`STOPPED / STARTING / SCAN_QR_CODE / WORKING / FAILED`), renders the QR string to a PNG itself, and exposes clean endpoints. The only PHP changes are retargeting `WahaClient` to the engine's paths and threading a new `preview` block through the existing `/send` and `/ui/send` delivery path; the guards (`@newsletter`-only, non-empty text) are untouched.

The engine auto-connects a stored device on boot and relies on whatsmeow's built-in auto-reconnect, so a container restart returns to `WORKING` on its own without a manual start, which is a reliability gain over WAHA. It runs internal-only on the docker network with no published host port and no API key: the gateway is its sole client, the QR reaches the browser only through the gateway's existing `/session/qr` proxy, and so the WAHA `X-Api-Key` disappears entirely.

The pipeline stays the brain. `PublishableDeal` gains a `getImageUrl()` getter (`FoundDeal` already carries the column), and `WahaChannelPublisher` sends the preview block alongside the unchanged message body. When the thumbnail fetch fails the engine degrades gracefully, posting the text and card without the image and returning success, so a transient CDN blip neither blocks a sale nor traps the ASIN in a retry loop; the `PostedDeal` "delivered ⇒ recorded" invariant is preserved.

Cutover is a hard swap in one PR: WAHA is removed from compose and a one-time QR re-pair is expected, since a fresh whatsmeow device cannot reuse WAHA's session store.

## Scope

### In scope

- As the operator, I can pair a WhatsApp account by scanning a QR code in the existing pairing UI, served by the whatsmeow engine through the unchanged gateway, so the engine can post on the account's behalf.
- As the operator, I can see live session status (`STOPPED → STARTING → SCAN_QR_CODE → WORKING`, `FAILED` on error) and log out / re-pair from the UI, with the engine mapping whatsmeow's connection state to those same words.
- As the operator, after a container restart with a paired device, the engine auto-connects and returns to `WORKING` with no manual step.
- As the operator, I can list owned `@newsletter` channels (OWNER/ADMIN) from the UI, backed by `GetSubscribedNewsletters`.
- As the system, when I publish a deal I send a text message plus a **compact link-preview card** carrying the product title and a self-generated thumbnail from the deal's `image_url`, so the post shows the product photo on the free tier.
- As the system, when the thumbnail fetch/decode/resize fails, I still post the text and card without the image and record the `PostedDeal`, so a transient image error does not block delivery.
- As the pipeline, I `POST /send` a `{chatId, text, preview}` through the gateway with the internal key and have the engine deliver it to the configured channel.
- As the operator, I can smoke-test the engine end to end from the manual send form without involving the pipeline.

### Out of scope

- **Paced auto-publish / ban safeguards.** No send-rate limiting, spacing, hourly ceiling, or session-WORKING pre-check is added here; publishing stays human-triggered and one-at-a-time exactly as today. Pacing rides with the still-deferred unattended-publish work.
- **Large (banner) preview cards.** Compact inline `JPEGThumbnail` only; no media upload to WhatsApp's servers for a high-quality card.
- **Card description text.** The card's `description` is left empty; the message body keeps the existing `price € emoji` line.
- **Running WAHA in parallel / feature-flagging the engine.** Hard cutover, no rollback window beyond `git revert`.
- **An engine-level API key or debug host port.** Network isolation only.
- **WAHA Plus.** Explicitly not purchased.
- **Multi-channel routing, media/attachments/polls, marketplaces beyond amazon.de** (unchanged from the product spec).

## Success Criteria

- Clicking Publish on a recorded deal with an affiliate URL and an `image_url` delivers a plain-text message plus a compact preview card that **shows the product photo** in the configured `@newsletter` channel.
- The message body is unchanged from today: German `12,99 €` + a random sale emoji, then the affiliate URL on its own line.
- A successful publish writes exactly one `PostedDeal(asin, snapshotPriceCents, postedAt)` row and sets `publishRequestedAt`; a failed publish writes neither.
- When the thumbnail cannot be fetched, the post still goes out (text + card, no image), is recorded, and a warning is logged; no retry trap.
- After a container restart with a paired device, `GET /session` reports `WORKING` without any manual start.
- The pairing UI and `templates/pairing.html.twig` need no change; the only PHP edits are `WahaClient` (retarget + `preview` field) and the controller/publisher plumbing for `preview`.
- WAHA is gone from `docker-compose`; `WAHA_API_KEY`/`WAHA_SESSION` are removed from the gateway env; the engine publishes no host port.
- Go unit tests cover the thumbnail pipeline, the whatsmeow-state → status-word mapping, and the HTTP handlers (against a fake `Engine`); the live whatsmeow path passes a manual pair-and-post smoke checklist.

## Constraints

- whatsmeow engine in Go using `go.mau.fi/whatsmeow`, stdlib `net/http`, `skip2/go-qrcode` for QR→PNG; session state in a SQLite store on a docker volume (mirrors WAHA's old `.sessions` volume, keeps the gateway dependency-free of Postgres).
- The engine is internal-only on the compose network: no published host port, no API key. The browser reaches the QR only via the gateway's existing `/session/qr`.
- Channels-only (`chatId` ends in `@newsletter`) and text-only, still enforced server-side in the PHP gateway; guards unchanged.
- Thumbnail: fetch `image_url` with a browser-like User-Agent, a short timeout and a source-size cap; decode JPEG/PNG; resize longest side to ~256px; re-encode JPEG q≈80; set as `JPEGThumbnail` with `previewType = IMAGE`.
- Engine `/send` body is `{chatId, text, preview:{url, title, image}}`; `matchedText`/`canonicalUrl` = the affiliate URL. `DeviceProps` default with companion display name "Deal Promoter". `GET /health` for the compose healthcheck.
- All money stays integer euro-cents; the body still formats `snapshotPriceCents / 100` as German `12,99 €`.
- `.env.example` only is edited; the real `.env` is maintained by hand.
- PHP 8.4+ / Symfony, consistent with the rest of the monorepo; the gateway stays free of `packages/shared` and Doctrine.

## Open Questions / Risks

- **ADR 0002's trust boundary moves.** Removing the WAHA `X-Api-Key` and running the engine keyless on an internal-only network changes that ADR's premise. Worth recording via `domain-docs`, alongside a new ADR for the engine swap that supersedes the dropped text-only ADR.
- **Channel rendering of custom-thumbnail previews** is confirmed by operator research but not yet re-verified against whatsmeow specifically; the manual smoke checklist is the first hard confirmation, and the graceful-degradation path is the fallback if a card ever renders imageless.
- **WhatsApp ban risk is behavioural, not request-agent.** whatsmeow lets us set `DeviceProps`, but bans are driven by send volume/velocity/spam reports. This build keeps sends manual, so the risk is unchanged from today; it becomes live only with the deferred auto-publish work.
- **Image CDN reliability.** The `m.media-amazon.com` thumbnail GET is a new external dependency per post; graceful degradation covers transient failures, but sustained CDN issues would mean image-less cards.

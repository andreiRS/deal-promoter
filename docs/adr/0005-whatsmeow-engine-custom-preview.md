# 5. Self-hosted whatsmeow engine with custom preview cards, replacing WAHA

Date: 2026-06-02
Status: Accepted

## Context

Channel posts carry the product image as their single strongest "this is a real
deal" signal, but on the free WAHA **Core** tier the preview image renders blank:
WEBJS fetches a thumbnail the channel client shows blank, GOWS fetches none, and
every image-bearing WAHA endpoint (`sendImage`, `send/link-custom-preview`)
returns `422 — available only in Plus version`. The root cause is that WAHA
auto-generates the card by fetching the Amazon affiliate page server-side, where a
captcha blocks it (WAHA issue #596).

Three options were on the table:

1. **Accept text-only posts** on WAHA Core — keep the blank-image card and live
   without the product photo. (The direction this ADR supersedes; never written
   up, dropped once the photo was judged essential and competing channels show it.)
2. **Buy WAHA Plus** solely to unblock previews — a recurring cost for a tool that
   only ever needs text posts with a working card, and even Plus would force a move
   off WEBJS plus a re-pair.
3. **Self-host the underlying library.** WAHA's GOWS engine wraps the MIT-licensed
   `go.mau.fi/whatsmeow`, whose `ExtendedTextMessage` proto exposes
   `title`/`matchedText`/`canonicalUrl`/`JPEGThumbnail`/`previewType` directly — so
   a custom preview is free and the captcha-gated page fetch disappears, and we
   already hold every field the card needs (`title`, `image_url`) per deal.

## Decision

Replace the WAHA container with a self-hosted **whatsmeow engine** — a new Go
service at `apps/whatsmeow-engine` built on `go.mau.fi/whatsmeow`. It does not
auto-generate previews; instead it hand-builds an `ExtendedTextMessage` with
`matchedText`/`canonicalUrl` set to the affiliate URL, `title` set to the product
title, and a small `JPEGThumbnail` it produces by fetching the deal's `image_url`
itself (browser-like UA, short timeout, source-size cap, resize to ~256px, JPEG
q≈80). Amazon's product page is never fetched for a preview, so the captcha that
breaks WAHA's path is irrelevant; the only Amazon hit is a plain GET to the image
CDN with a request agent we control. When the thumbnail fetch/decode/resize fails,
the engine degrades gracefully — posts the text and card without the image and
still returns success — so a transient CDN blip neither blocks a sale nor traps the
ASIN in a retry loop.

The PHP `whatsapp-service` gateway (ADR 0001) stays in place unchanged in shape.
The engine absorbs every WAHA-ism so the gateway and its pairing UI need no
behavioural change: it returns the **same** status vocabulary
(`STOPPED / STARTING / SCAN_QR_CODE / WORKING / FAILED`), renders the QR string to
a PNG itself, and exposes clean session-less paths. The engine session lives in a
SQLite store on a docker volume (replacing WAHA's `.sessions` mount) and relies on
whatsmeow's built-in auto-reconnect, so a container restart returns to `WORKING`
on its own with no manual start.

Cutover is a hard swap in one PR: WAHA is removed from `docker-compose`, and a
one-time QR re-pair is expected because a fresh whatsmeow device cannot reuse
WAHA's session store. No parallel-run or feature flag; rollback is `git revert`.

## Consequences

- The product photo shows in the channel card on the free tier — the whole point
  of the swap — with no recurring WAHA Plus cost and no captcha-gated page fetch.
- Auto-reconnect on a stored device is a reliability gain over WAHA: a restart
  self-heals to `WORKING` instead of needing an operator start.
- The engine runs keyless and internal-only (no published host port); the gateway
  is its sole client, so the WAHA `X-Api-Key` disappears entirely. This changes the
  premise of ADR 0002 — see that ADR's amendment for how the trust boundary moved.
- Cost: a new Go service to build, run, and deploy, and a one-time re-pair at
  cutover. A new external dependency per post (the `m.media-amazon.com` thumbnail
  GET); graceful degradation covers transient failures, but sustained CDN issues
  would mean image-less cards.
- ADR 0001 still holds — the standalone gateway shape and its `@newsletter`-only,
  non-empty-text guards are unchanged; only the backend it talks to changed, from
  WAHA to the whatsmeow engine.
- Ban risk is unchanged: it is behavioural (send volume/velocity), not request-agent,
  and sends stay manual and one-at-a-time. It becomes live only with the deferred
  auto-publish work.

# 5. Text-only channel posts on WAHA Core

Date: 2026-06-01
Status: Accepted

## Context

Channel posts go out as `sendText` with the price, a sale emoji, and the
affiliate link (`WahaChannelPublisher`). WhatsApp renders these as a link
*preview card* (title + description + domain), but the **preview image is
blank** on both desktop and mobile, while other deal channels posting "just a
link" show the product photo. We investigated whether the image can be made to
appear on our free WAHA **Core** tier (currently `WHATSAPP_DEFAULT_ENGINE: WEBJS`,
image `devlikeapro/waha:chrome`).

Findings, all reproduced live against the running container:

- **WEBJS auto preview** (`linkPreview: true` + `linkPreviewHighQuality: true`):
  WAHA *does* fetch and embed a valid product thumbnail (decoded from the message
  payload — a real image), but the WhatsApp client renders it **blank in the
  channel**. The high-quality CDN upload WEBJS produces is not rendered.
- **GOWS auto preview** (plain or with the HQ flag): generates a **text-only**
  preview (`previewType: 0`, no `jpegThumbnail`/`thumbnailDirectPath`) — the
  image is never fetched at all.
- **`POST /api/sendImage`** (attach a real photo): returns `422` —
  *"available only in Plus version"*. Plus-gated on every engine.
- **`POST /api/send/link-custom-preview`** (supply our own title/desc/image,
  which we *could* do since each deal already has an `image_url`): also
  **Plus-gated** per WAHA's engine feature matrix.

Switching the engine to GOWS was tried and reverted; it requires a re-pair and
does not solve the image problem. The other channels that show images are not on
free WAHA Core — they are on WAHA Plus, the official WhatsApp Business API, or
posting from a real phone (which embeds the thumbnail natively).

## Decision

Accept **text-only posts** on WAHA Core. Keep the engine on **WEBJS** and the
`WahaChannelPublisher` sending plain `sendText` (price + emoji + affiliate link),
with no preview flags. Do **not** pay for WAHA Plus at this time.

## Consequences

- Posts keep going out reliably with the price, emoji, and affiliate link. The
  link preview card still shows the product title and description; only the image
  is absent.
- Getting a product image into the post is a **paid** decision, not a code one.
  The unblock is WAHA Plus + `POST /api/send/link-custom-preview` driven by the
  deal's existing `image_url` — a one-call change to `WahaChannelPublisher` if we
  ever subscribe. No engine switch is needed for that path.
- Do not re-investigate "fix the preview image on Core" — every image-bearing
  WAHA endpoint is Plus-gated, and the one free path that embeds an image (WEBJS
  HQ) renders blank in channels. This is a tier limit, not a bug in our code.

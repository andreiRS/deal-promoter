# WhatsApp Link Preview: Native vs. Engine-Sent (for Deal Promoter)

Why an Amazon deal link pasted by hand into WhatsApp shows a rich card (badge + star
rating + review count), while the same link published through our pipeline shows only a
plain product image.

> Investigated 2026-07-08 against `apps/whatsmeow-engine/` and the upstream publish chain.
> All file:line references below are to that snapshot.

## TL;DR

The difference is **who builds the preview card**.

- **Pasted by hand:** WhatsApp's own servers fetch the Amazon page, read its
  OpenGraph/meta tags + structured data, and Amazon serves back a rich card ("Limited
  time deal" badge, 4.5★, 11,151 reviews, title, description, image). WhatsApp does the
  enrichment.
- **Published via our engine:** WhatsApp does **not** re-fetch the link. The engine sends a
  fully pre-built `ExtendedTextMessage` with the preview fields already filled in, and
  WhatsApp renders exactly those fields, nothing more. The engine never scrapes the Amazon
  page.

Result: our card only ever carries a title, an empty description, and a plain resized
product photo. No badge, stars, reviews, or description are set anywhere.

## The publish chain

```
pipeline (HttpChannelPublisher)  ──POST /send──▶  whatsapp-service (PHP gateway)  ──POST /send──▶  whatsmeow-engine (Go)  ──▶  whatsmeow SendMessage
```

- `apps/pipeline/src/Channel/HttpChannelPublisher.php:70-82` builds the JSON payload:
  `chatId`, `text` ("{price} € {emoji}\n{affiliateUrl}"), and a `preview` object with
  `url`, `title`, `image` (Amazon CDN URL), `highRes` (hardcoded `true`, line 42).
- The image URL originates from Keepa: `packages/shared/src/Keepa/DealParser.php:121-135`
  decodes Keepa's image char-code array into an Amazon CDN filename. It is the plain
  product photo, **not** the composited card image WhatsApp's fetcher gets from Amazon.
- Gateway (`apps/whatsapp-service`) forwards the same shape to the engine's `POST /send`.

## What the engine puts on the card

The engine always sends a native WhatsApp link preview: a `waE2E.ExtendedTextMessage`
(never an `ImageMessage` with a caption). Built in `BuildExtendedTextMessage`
(`apps/whatsmeow-engine/send.go:26-39`):

| Field | Value | Note |
|---|---|---|
| `Text` | message body | price + emoji + affiliate URL |
| `MatchedText` | `preview.URL` | the affiliate link (no separate `CanonicalURL` is set) |
| `Title` | `preview.Title` | product title, caller-supplied |
| `Description` | `""` | **hardcoded empty string, always** (`send.go:32`) |
| `PreviewType` | `IMAGE` | |
| `JPEGThumbnail` | resized product image | only when thumbnail bytes exist |

Never set: `CanonicalURL`, `MediaKey`/`ThumbnailEncSHA256` (newsletter media is
unencrypted), `ContextInfo`.

### Thumbnail handling

The engine downloads the raw bytes at `preview.image` and only decodes → bilinear resize →
JPEG re-encode (quality 80). No OpenGraph scraping, no HTML parsing, no compositing (no
badge/stars/text drawn onto pixels). `FetchImageBytes`
(`apps/whatsmeow-engine/thumbnail.go:116-147`) even rejects any non-`image/*`
content-type, so it could not read a product page.

Two paths, chosen by `req.Preview.HighRes` (pipeline always sets `true`):

- **Low-res (default):** resize longest side to 256px, inline as `JPEGThumbnail`.
- **High-res:** derive a 256px inline fallback + an 800px JPEG, upload the 800px via
  `client.UploadNewsletter(..., MediaLinkThumbnail)`, and set `ThumbnailDirectPath`,
  `ThumbnailSHA256`, `ThumbnailWidth`, `ThumbnailHeight` (`send.go:58-66`). Any
  fetch/upload failure degrades gracefully and still posts the card.

## Why we can't just "make WhatsApp fetch it"

WhatsApp only auto-enriches a link when a normal client sends plain text and lets the
client/server generate the preview. Because the engine supplies the preview fields itself,
WhatsApp trusts and renders them as-is and will not override with its own fetch. Getting the
rich look means **we** must supply it.

## Options to close the gap

1. **Composite the thumbnail ourselves (closest match).** Draw the "Limited time deal"
   badge + star rating + review count onto the product image in `thumbnail.go` before
   encoding, using rating/deal data already available from Keepa. Fully under our control.
2. **Fill the `Description` field (cheapest).** It is hardcoded `""` today. Pass e.g.
   `★ 4.5 (11,151) · Limited time deal` so the card shows text under the title. Requires
   the pipeline to pass a `description` and the engine to stop hardcoding empty.
3. **Fetch Amazon's real `og:image` (recommended, see follow-up below).** Scrape the
   product page's OpenGraph image and use it as `preview.image`. Verified 2026-07-08 that
   Amazon already bakes the badge + stars + review count into this one image, so this
   reproduces the native card exactly with no compositing on our side.

## Follow-up (2026-07-08): where the badge + stars actually come from

**Key correction to option 3:** the badge/stars are **not** structured data WhatsApp draws.
They are pre-composited by Amazon directly into the `og:image` URL, via Amazon's
image-server URL operators. WhatsApp just displays that string.

WhatsApp chats are end-to-end encrypted, so WhatsApp's servers never see the URL. The
**sending client** (Desktop/phone) fetches the page and builds the preview. It fetches as
the preview bot, and Amazon detects that User-Agent and returns a rich, composited image.

Reproduced locally:

```bash
curl -sL -A "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)" \
  "https://www.amazon.de/dp/B0D8WP8VSG?tag=shahsiahde-21&linkCode=ogi&th=1&psc=1"
# HTTP 200, contains <meta property="og:image" ...>
```

The returned `og:image` for that ASIN:

```
https://m.media-amazon.com/images/I/714yV1w2+DL.jpg
  _BO30,255,255,255_          # white background/border
  _UF900,900_                 # fit to 900x900
  _SR1910,1000,0,R_           # scale + rotate
  _PIlimited-time-deal-de,TopLeft,60,374_        # "Limited time deal" badge (localized -de)
  _ZJ...jQsNTwv...==,60,515,420,420,0,0_         # base64 text overlay: rating "4,5"
  _PIRIOFOURANDHALF-medium-V2,TopLeft,190,523_   # 4.5-star graphic
  _ZJ...KDExLjE1MSk...==,650,515,420,420,0,0_    # base64 text overlay: "(11.151)"
  _QL100_.jpg                 # quality 100
```

`og:title` came back as `Angebot: G-STAR RAW Regular-Fit Jeans Rovic Zip` and `og:description`
was also present. So all of the richness lives in that single image URL.

### Implication for us

To match the native card, fetch the product page with the `facebookexternalhit` (or
`WhatsApp`) User-Agent, scrape `<meta property="og:image">`, and pass that URL as
`preview.image` instead of the plain Keepa CDN photo. No client-side compositing needed.

Caveats:
- The rich `og:image` is only served to the preview-bot UA; a normal browser UA gets a
  different page. We must send that UA when scraping.
- The badge/rating are composited by Amazon at fetch time, so they reflect live deal/rating
  state, which is what we want.
- Amazon may rate-limit/block bot UAs; needs a retry/fallback to the current CDN photo.

## Key files

- `apps/whatsmeow-engine/send.go` — message construction and send.
- `apps/whatsmeow-engine/thumbnail.go` — fetch/resize/encode pipeline.
- `apps/whatsmeow-engine/engine.go` — `SendRequest`/`PreviewMeta` (no `Description` input).
- `apps/pipeline/src/Channel/HttpChannelPublisher.php` — payload the pipeline sends.
- `apps/whatsapp-service/src/WhatsApp/WhatsAppClient.php` — gateway → engine forwarding.
- `packages/shared/src/Keepa/DealParser.php` — where the image CDN URL comes from.

## Prototype (2026-07-08)

**Verdict: the hypothesis holds.** Scraping Amazon's composited `og:image` and feeding it
through the engine's real resize functions reproduces the rich card (badge + stars + review
count) at both 256px and 800px. The plain Keepa-style CDN photo shows none of them. This was
verified by viewing the actual output pixels, not by reasoning.

### What was built

A throwaway Go command that calls the engine's real code (not a reimplementation):

- `apps/whatsmeow-engine/cmd/ogproof/main.go` — scrapes the product page as a preview bot,
  extracts `og:image`, fetches both the composited image and the plain base image via the
  engine's real `engine.FetchImageBytes`, then runs both through the engine's real
  `engine.TransformThumbnail` (256px inline) and `engine.TransformHighResThumbnail` (800px
  upload). Delete after the prototype.

Run: `go run ./cmd/ogproof/ <outDir>` from `apps/whatsmeow-engine`.

### The proof (observed)

Ran against ASIN `B0D8WP8VSG` (G-STAR RAW cargo pants). Output images generated:

| Image | Size | What I saw |
|---|---|---|
| `og_highres_800x418.jpg` | 25 KB | Red "Zeitlich begrenztes Angebot" badge, "4,5", 4.5 orange stars, "(11.151)" — all sharp and legible, product photo on the right. |
| `og_thumb_256.jpg` | 4.7 KB | Same badge, "4,5", stars and "(11.151)" — still clearly legible even at this small size. |
| `base_highres_640x800.jpg` | 36 KB | Just the pants on white. No badge, no stars, no review count. |
| `base_thumb_256.jpg` | 6.6 KB | Same plain product photo, nothing overlaid. |

The resize does **not** degrade the overlays. At 800px everything is crisp. At 256px the
badge text, the "4,5", the star row and "(11.151)" are all still readable — the JPEG quality-80
re-encode does not smear them into mush. Observed by opening every file.

### The og:image and what the operators do

Confirmed the composited URL again (full, not truncated this time):

```
https://m.media-amazon.com/images/I/714yV1w2+DL.jpg
  _BO30,255,255,255_ _UF900,900_ _SR1910,1000,0,R_
  _PIlimited-time-deal-de,TopLeft,60,374_
  _ZJ<base64>,60,515,420,420,0,0_        # rating text
  _PIRIOFOURANDHALF-medium-V2,TopLeft,190,523_   # 4.5-star graphic
  _ZJ<base64>,650,515,420,420,0,0_       # review-count text
  _QL100_.jpg
```

The two `_ZJ...` operators are base64-encoded Pango-style text spans. Decoded:

```
<span foreground="#0F1111" font="AmazonEmber 66">4,5</span>
<span foreground="#565959" font="AmazonEmber 66">(11.151)</span>
```

So Amazon renders the rating and review count as live text into the image server-side. The
base image (plain product photo) is that same URL truncated at `.jpg` — the operator chain is
what adds everything.

### Surprises

- **The preview-bot UA is now blocked, and it matters which one.** The
  `facebookexternalhit/1.1` UA that the earlier research used returned HTTP 503 with an Amazon
  captcha/anti-bot page on every retry from this IP today. Switching to a literal `WhatsApp/2.23`
  UA returned HTTP 200 with the composited `og:image`. Inference: Amazon rotates which bot UAs
  it trusts and/or rate-limits by IP; production must not hardcode one UA and must treat a
  scrape failure as normal, not exceptional.
- **Response is gzip-compressed.** A raw `curl` without `--compressed` saved 381 KB of
  unreadable bytes and my grep found no tags. Go's `http.Transport` handles this transparently
  as long as you do not set `Accept-Encoding` yourself; the prototype relies on that.
- **The composited image has a different aspect ratio than the plain photo.** The composited
  canvas is wide (resized to 800x418), the plain photo is portrait (640x800). The card layout
  changes shape when we switch images. Worth eyeballing in a real WhatsApp client before
  shipping, since the wide image includes deliberate left-side whitespace for the overlays.
- **The `+` in the CDN filename (`714yV1w2+DL.jpg`) is a literal path character**, not a query
  space. Go's URL parsing and the CDN both keep it literal, so no special handling was needed
  in this prototype — but any code that URL-encodes the path would break it.

### Live WhatsApp publish: NOT run

Nothing was listening on port 8080 (engine) or 8081 (gateway), and there is no `store.db`, so
no WhatsApp session is paired. Pairing needs a human QR scan, which the task forbids. The
offline pixel proof is sufficient, so I skipped the live publish deliberately.

### What production would need

1. **Add a scrape step before publish.** Given a product page URL, fetch it with a preview-bot
   UA (with gzip decode), extract `<meta property="og:image">`, and use that URL as
   `preview.image` instead of the Keepa CDN photo. The cleanest home is the PHP pipeline — a
   small shared Amazon/Keepa helper called from `HttpChannelPublisher.php` where the `image`
   field is currently set — so the engine stays a dumb image resizer and nothing about the
   engine changes.
2. **Fallback to the current CDN photo on any scrape failure.** Given today's 503, treat a
   failed/blocked scrape as expected and fall back to the plain Keepa image so a deal still
   ships (plain card) rather than not shipping. Log the fallback.
3. **UA handling.** Do not hardcode `facebookexternalhit`; it was blocked today. Prefer the
   `WhatsApp` UA (it worked) and consider a small retry across a couple of bot UAs.
4. **No engine change is required.** The engine already fetches and resizes any image URL
   correctly; the overlays are baked into the URL Amazon serves, so passing the composited URL
   through the existing path is enough. (Observed: the shipped `TransformThumbnail` /
   `TransformHighResThumbnail` produced the rich thumbnails unchanged.)
5. **Sanity-check the aspect ratio in a real client** before rollout, since the composited
   image is wide where the plain photo is portrait.

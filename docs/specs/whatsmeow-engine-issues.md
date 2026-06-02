# whatsmeow Engine — Issues

Vertical slices for `docs/specs/whatsmeow-engine.md`. Branch: `feat/whatsmeow-custom-preview`.

All live-WhatsApp behavior is verified by hand in the cutover slice; implementation slices are AFK-able (built behind the `Engine` interface, unit-tested with fakes). Slices 1 and 2 have no dependencies and can start in parallel.

---

## 1. Engine scaffold: HTTP server, `Engine` interface, status mapping, `/health`, `/session`

**Attendance:** unattended
**Blocked by:** None — can start immediately.

### What to build

A new Go service at `apps/whatsmeow-engine`: stdlib `net/http` server, an `Engine` interface that the rest of the service depends on, and the status-word vocabulary the PHP gateway already expects. Wire `/health` and `/session` against a fake in-memory `Engine` so the seam is exercised before any whatsmeow code exists.

The status words are fixed by the gateway: `STOPPED / STARTING / SCAN_QR_CODE / WORKING / FAILED`. The mapping from whatsmeow's connection state to these words is a pure function, tested directly.

```go
type Engine interface {
    Status() string          // one of the five status words
    StartPairing() error     // begin first-time pairing (no stored device)
    Logout() error
    QRImage() ([]byte, bool) // PNG bytes, ok=false when no QR available
    Channels() ([]Channel, error)
    Send(req SendRequest) (string, error) // returns message id
}
```

### Acceptance criteria

- [ ] `apps/whatsmeow-engine` Go module builds and the server boots.
- [ ] `GET /health` returns 200.
- [ ] `GET /session` returns `{status}` using the five-word vocabulary, backed by a fake `Engine`.
- [ ] Pure whatsmeow-state → status-word mapping is unit-tested across all states.
- [ ] HTTP handlers are unit-tested against a fake `Engine`.

### Blocked by

None — can start immediately.

---

## 2. Thumbnail pipeline: fetch `image_url` + decode + resize + JPEG encode

**Attendance:** unattended
**Blocked by:** None — can start immediately (parallel with #1).

### What to build

A pure function that fetches a product `image_url` and produces compact JPEG thumbnail bytes for `JPEGThumbnail`. Fetch with a browser-like User-Agent, a short timeout, and a source-size cap. Decode JPEG/PNG, resize the longest side to ~256px preserving aspect (`x/image/draw`), re-encode JPEG at q≈80. Surface a typed error on fetch/decode/resize failure so the caller (send slice) can degrade gracefully.

### Acceptance criteria

- [ ] Given a fixture image, returns valid JPEG bytes resized to ~256px longest side.
- [ ] Honors the User-Agent, timeout, and source-size cap on the fetch.
- [ ] Returns a typed error (not a panic) on fetch failure, non-image content, or decode failure.
- [ ] Unit-tested with fixture images for the success and failure paths.

### Blocked by

None — can start immediately.

---

## 3. whatsmeow client: SQLite store, boot-connect, real status

**Attendance:** unattended to implement (live behavior verified in the cutover slice)
**Blocked by:** #1

### What to build

The real `Engine` implementation's foundation, replacing slice 1's fake `Status()`. Open a SQLite store (`sqlstore.New(ctx, "sqlite3", "file:store.db?_foreign_keys=on", ...)`) on a path that will become a docker volume; `NewClient` with `DeviceProps` default and companion display name "Deal Promoter"; enable whatsmeow's auto-reconnect. On boot, if a stored device exists, `Connect()`; if none, stay disconnected. Derive `Status()` from real client state (`IsConnected`/`IsLoggedIn` → `WORKING`, handshake in progress → `STARTING`, no device / logged out → `STOPPED`, connect error → `FAILED`).

No pairing or QR yet — with no stored device the status is simply `STOPPED`.

### Acceptance criteria

- [ ] SQLite store opens on the configured path and persists across restarts.
- [ ] With a stored device, the client auto-connects on boot and `Status()` reaches `WORKING` with no manual start.
- [ ] With no stored device, `Status()` is `STOPPED`.
- [ ] `Status()` reflects `STARTING` mid-handshake and `FAILED` on connect error.
- [ ] Transient disconnects recover via auto-reconnect without operator action.

### Blocked by

#1

---

## 4. First-time pairing: `StartPairing`, QR→PNG, `Logout`

**Attendance:** unattended to implement (live behavior verified in the cutover slice)
**Blocked by:** #3

### What to build

The operator pair/unpair loop. `StartPairing` calls `Connect()` and consumes the `GetQRChannel` events, holding the latest QR string and driving `Status()` to `SCAN_QR_CODE` while codes flow (and `STARTING` after a scan, until logged in). `QRImage()` renders the held QR string to a PNG (`skip2/go-qrcode`). `Logout()` clears the device and returns to `STOPPED`.

After this slice the engine can pair from cold and unpair, but cannot yet list channels or send.

### Acceptance criteria

- [ ] With no device, `StartPairing` drives `SCAN_QR_CODE` and `QRImage` returns a scannable PNG.
- [ ] Scanning the QR transitions to `WORKING`.
- [ ] `QRImage` returns `ok=false` when no QR is available (e.g. already `WORKING`).
- [ ] `Logout` clears the device and returns to `STOPPED`.

### Blocked by

#3

---

## 5. Channel list: `GetSubscribedNewsletters` mapping + filter

**Attendance:** unattended to implement (live behavior verified in the cutover slice)
**Blocked by:** #4

### What to build

Implement `Channels()` via `GetSubscribedNewsletters`, mapping each to `{id, name, role}` and filtering to OWNER/ADMIN roles with `@newsletter` ids (mirrors today's gateway filter). Requires a paired, connected client.

### Acceptance criteria

- [ ] `Channels()` returns owned `@newsletter` channels (OWNER/ADMIN) as `{id, name, role}`.
- [ ] Subscriber/guest newsletters and non-`@newsletter` ids are filtered out.
- [ ] Returns an empty list (not an error) when the account owns no channels.

### Blocked by

#4

---

## 6. Send with compact preview: `/send` builds `ExtendedTextMessage` + graceful degradation

**Attendance:** unattended to implement (live send verified in the cutover slice)
**Blocked by:** #1, #2, #4

### What to build

The `/send` endpoint and the real `Engine.Send`. Body: `{chatId, text, preview:{url, title, image}}`. Build an `ExtendedTextMessage` with `text` = body, `matchedText`/`canonicalUrl` = `preview.url`, `title` = `preview.title`, empty description, `previewType = IMAGE`, and `JPEGThumbnail` = bytes from the slice-2 pipeline. Send to the `@newsletter` JID via whatsmeow `SendMessage`.

Graceful degradation: if the thumbnail fetch/decode fails, log a warning and send the message **without** `JPEGThumbnail` (text + card title + link still go out); the send still returns success.

```
ExtendedTextMessage{
  Text:          body,
  MatchedText:   preview.url,
  CanonicalURL:  preview.url,
  Title:         preview.title,
  PreviewType:   IMAGE,
  JPEGThumbnail:  <thumbnail bytes, omitted on failure>,
}
```

### Acceptance criteria

- [ ] `POST /send` with a valid preview composes the `ExtendedTextMessage` with all preview fields set.
- [ ] Thumbnail failure degrades to a send without `JPEGThumbnail`, logs a warning, and still returns success.
- [ ] Handler logic is unit-tested against a fake `Engine` + a stubbed thumbnail pipeline (success + degrade paths).
- [ ] Real `SendMessage` targets the `@newsletter` JID.

### Blocked by

#1, #2, #4

---

## 7. PHP gateway: retarget `WahaClient` to the engine + thread `preview`

**Attendance:** unattended
**Blocked by:** #6

### What to build

Point the PHP `whatsapp-service` at the new engine. Rewrite `WahaClient`'s ~6 methods to call the engine's clean paths (`GET /session`, `POST /session/start`, `POST /session/logout`, `GET /qr`, `GET /channels`, `POST /send`) instead of WAHA's. Thread a `preview` block through the existing `/send` and `/ui/send` delivery path. The `@newsletter`-only and non-empty-text guards stay unchanged, and the pairing UI / `pairing.html.twig` need no edits.

### Acceptance criteria

- [ ] `WahaClient` calls the engine's paths and returns the same shapes the controllers/UI expect.
- [ ] `/send` and `/ui/send` accept and forward the `preview` block; guards unchanged.
- [ ] The pairing UI and template are byte-for-byte unchanged.
- [ ] `WahaClient` + controller tests pass via `MockHttpClient`.

### Blocked by

#6

---

## 8. Pipeline: `PublishableDeal.getImageUrl()` + `WahaChannelPublisher` preview block

**Attendance:** unattended
**Blocked by:** #7

### What to build

Widen `PublishableDeal` with `getImageUrl(): ?string` (`FoundDeal` already carries the column; update any test fakes/other implementers). `WahaChannelPublisher` sends the `preview` block alongside the unchanged message body: `preview.url` = affiliate URL, `preview.title` = product title, `preview.image` = `image_url`. The `PostedDeal` "delivered ⇒ recorded" invariant is preserved.

### Acceptance criteria

- [ ] `PublishableDeal` exposes `getImageUrl()`; all implementers/fakes compile.
- [ ] `WahaChannelPublisher` posts `{chatId, text, preview:{url, title, image}}` with the body unchanged.
- [ ] A successful send still writes exactly one `PostedDeal` row; a failed send writes none.
- [ ] Publisher unit tests pass.

### Blocked by

#7

---

## 9. Compose hard cutover + manual smoke

**Attendance:** attended
**Blocked by:** #1, #2, #3, #4, #5, #6, #7, #8

### What to build

Hard cutover in `docker-compose`: remove the `waha` service and its `.sessions` mount, add the `whatsmeow-engine` service with a SQLite session volume, a `/health` healthcheck, and no published host port (internal-only). Remove `WAHA_API_KEY`/`WAHA_SESSION` from the gateway env and update `.env.example`. Then verify live: re-pair by scanning the QR, list the channel, publish a real recorded deal, and confirm the channel post shows the product image in a compact card.

### Acceptance criteria

- [ ] `waha` is gone from compose; `whatsmeow-engine` runs internal-only with a session volume + healthcheck.
- [ ] `WAHA_API_KEY`/`WAHA_SESSION` removed from gateway env; `.env.example` updated.
- [ ] QR re-pair succeeds and the gateway reports `WORKING`.
- [ ] Listing channels from the UI returns the owned channel.
- [ ] Publishing a real deal delivers a post whose compact card shows the product photo.
- [ ] A restart of the engine container returns to `WORKING` with no manual start.

### Blocked by

#1, #2, #3, #4, #5, #6, #7, #8

---

## 10. ADR: engine swap + ADR 0002 boundary note

**Attendance:** unattended (via `domain-docs`)
**Blocked by:** #9

### What to build

Record the engine swap as a new ADR (whatsmeow replaces WAHA; supersedes the dropped text-only ADR) and note that ADR 0002's trust boundary moved (no engine API key, internal-only docker network). Use `domain-docs`.

### Acceptance criteria

- [ ] New ADR documents the whatsmeow engine swap and the custom-preview approach.
- [ ] ADR 0002 is annotated that the WAHA `X-Api-Key` is gone and the engine is keyless/internal-only.

### Blocked by

#9

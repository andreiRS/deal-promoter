# High-Res Preview Card — Issues

Vertical slices for `docs/specs/high-res-preview-card.md`. Branch: `feat/highres-preview-card`.

The large-card behaviour is verified by hand in slice 4; implementation slices 1–3 are AFK-able (built behind injected seams, unit-tested with fakes/stubs). Slices 1 (Go builder) and 3 (PHP plumbing) have no dependencies and can start in parallel. The whole feature is additive and default-off: `highRes:false` keeps today's inline-only path byte-for-byte.

See `docs/adr/0006-high-res-preview-thumbnail.md` for the mechanism and the open `IMAGE` vs `NONE` verification risk.

---

## 1. High-res thumbnail builder: resize-to-800 + `UploadNewsletter` seam + map onto `ExtendedTextMessage`

**Attendance:** unattended
**Blocked by:** None — can start immediately.

### What to build

The pure, testable core of the high-res path, with no `/send` wiring yet. Two pieces:

1. A larger-thumbnail transform: reuse the `thumbnail.go` pipeline but resize the longest side to **800px** (aspect preserved, no upscaling, no padding), JPEG q≈80, returning both the bytes **and** the actual resized width/height (the existing 256px inline transform stays as-is for the fallback).
2. An injected `uploadThumbnail` collaborator (signature mirrors `fetchThumbnail`/`sendMessage` in `sendDeps`) plus a builder that maps an upload result onto the `ExtendedTextMessage` high-res fields.

In production the collaborator wraps `Client.UploadNewsletter(ctx, jpeg, whatsmeow.MediaLinkThumbnail)` (newsletter media is unencrypted → `MediaKey`/`FileEncSHA256` empty by design). The field mapping:

```
resp.DirectPath   -> ThumbnailDirectPath
resp.FileSHA256   -> ThumbnailSHA256
actual dims       -> ThumbnailWidth / ThumbnailHeight
resp.Handle       -> carried out for SendRequestExtra{MediaHandle} (slice 2)
PreviewType        = IMAGE   // unchanged; verification risk tracked in slice 4
JPEGThumbnail      = 256px inline bytes  // fallback, still set
```

### Acceptance criteria

- [ ] An 800px transform returns JPEG bytes + actual (w,h), preserving aspect, never upscaling; a square source yields a square result.
- [ ] The inline 256px transform is unchanged.
- [ ] `BuildExtendedTextMessage` (or a high-res variant) sets `ThumbnailDirectPath`/`ThumbnailSHA256`/`ThumbnailWidth`/`ThumbnailHeight` from an upload result, keeps `JPEGThumbnail` as fallback, and leaves `PreviewType = IMAGE`.
- [ ] The upload collaborator is an injected seam in `sendDeps`, unit-tested with a stub (no live whatsmeow client).
- [ ] Existing low-res `BuildExtendedTextMessage` / thumbnail tests stay green.

### Blocked by

None — can start immediately.

---

## 2. `/send` high-res wiring: `preview.highRes` flag, dual-thumbnail, tiered fallback, `MediaHandle`

**Attendance:** unattended (live large-card render verified in slice 4)
**Blocked by:** Slice 1.

### What to build

Wire the slice-1 builder into `Engine.Send` and the `/send` handler behind the new flag. Extend `sendRequestBody`/`SendRequest`/`PreviewMeta` with `highRes bool` (default `false`). On `highRes:false`, behaviour is exactly today's (single inline thumbnail, no upload). On `highRes:true`, fetch the source once, produce both the inline 256px and the 800px upload, reference the uploaded thumbnail, and thread the handle through send via `SendRequestExtra{MediaHandle}`.

Tiered graceful fallback, never failing the send:

```
fetch fails                  -> send card with no image            (warn)
fetch ok, upload fails       -> send small inline card             (warn)
fetch ok, upload ok          -> send high-res card + inline fallback
```

The `sendMessage` seam must carry an optional media handle through to `client.SendMessage`. Low-res path passes no handle (unchanged).

### Acceptance criteria

- [ ] `/send` accepts `preview.highRes` (default `false`); omitted/`false` reproduces today's request/response and message bytes.
- [ ] `highRes:true` with a successful upload produces a message carrying both the uploaded high-res thumbnail fields and the inline fallback, sent with the media handle.
- [ ] Upload failure (fetch ok) falls back to the inline-only card and still returns success; a warning is logged.
- [ ] Fetch failure sends with no image and still returns success; a warning is logged.
- [ ] All three outcomes are unit-tested via the injected fetch/upload/send seams; existing send tests stay green.

### Blocked by

Slice 1.

---

## 3. Thread `highRes` through the PHP gateway + pipeline publisher

**Attendance:** unattended
**Blocked by:** None — can start in parallel with slice 1.

### What to build

Pass-through plumbing for the flag across the two PHP apps. The gateway (`whatsapp-service`) forwards `preview.highRes` from its inbound `/send` to the engine `/send` body via `WhatsAppClient::sendText` + `ChannelController`, defaulting to `false` when absent. The pipeline (`HttpChannelPublisher`) sets `preview.highRes` on the request it builds — decide the value from the `PublishableDeal`/config (start with a single explicit source of truth, e.g. always `true` for the high-res rollout, or a config toggle; keep it one obvious place).

No rendering logic here — the flag is an opaque boolean the engine interprets.

### Acceptance criteria

- [ ] Gateway forwards `preview.highRes` to the engine; absent → `false`; existing `/send` + `/ui/send` tests stay green.
- [ ] Pipeline publisher includes `preview.highRes` in the `/send` body it sends, from one clear source of truth.
- [ ] `phpunit`/`phpstan`/`php-cs-fixer` pass in both PHP apps.

### Blocked by

None — can start in parallel with slice 1.

---

## 4. Live verification on IronApiTest: confirm large card + settle `IMAGE` vs `NONE`

**Attendance:** attended — manual live send + eyeball.
**Blocked by:** Slices 2 and 3.

### What to build

A single manual end-to-end send to the IronApiTest channel with `highRes:true`, confirming the card renders the large, full-width product image (matching the target screenshot) and that a square Amazon image renders as a square large card. Confirm a `highRes:false` send still renders the small inline card.

This slice resolves ADR 0006's open risk: if the high-res card renders **small** despite the upload, flip the high-res `PreviewType` from `IMAGE` to `NONE` (one-line change) and re-send to confirm. Record the outcome in ADR 0006.

### Acceptance criteria

- [ ] A live `highRes:true` send to IronApiTest renders the large, full-width image card.
- [ ] A live `highRes:false` send renders the unchanged small inline card.
- [ ] `PreviewType` outcome (`IMAGE` held, or flipped to `NONE`) is recorded in ADR 0006.
- [ ] The forced upload-failure path is observed degrading to the inline card in a real send (optional manual check).

### Blocked by

Slices 2 and 3.

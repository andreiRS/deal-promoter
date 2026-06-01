# WhatsApp Service — Issues

Vertical slices for [`whatsapp-service.md`](whatsapp-service.md). Dependency
order is essentially linear (pairing → send → publish), since each stage needs a
live session from the one before. Three slices are inherently **attended** — a
real QR scan and a real channel delivery cannot be unit-tested.

| # | Slice | Attendance | Depends on |
|---|-------|------------|------------|
| 1 | Gateway app scaffold + runtime | unattended | — |
| 2 | WahaClient + WhatsApp pairing UI | attended | 1 |
| 3 | Channel list + manual send form | attended | 2 |
| 4 | Gated machine `/send` endpoint | unattended | 3 |
| 5 | Pipeline `WahaChannelPublisher` + review wiring | unattended | 4 |
| 6 | Live end-to-end verification | attended | 5, 2 |

---

## 1. Gateway app scaffold + runtime

### What to build

A new `apps/whatsapp-service` Symfony app (PHP 8.5 / Symfony 8) that boots in
Docker alongside WAHA, with an index route returning 200. No WhatsApp behavior
yet — this is the runnable shell every later slice builds on.

The service is a standalone gateway: **no `packages/shared`, no Doctrine** (ADR
0001). Its Dockerfile clones `apps/pipeline/Dockerfile` (`php:8.5-cli` + composer
+ `intl`) but **drops** `pdo pdo_pgsql` / `libpq-dev` — there is no database. Serve
with `php -S 0.0.0.0:8000 -t public`.

Wire the two compose services. The WAHA stub already commented into the root
`docker-compose.yml` is wrong — **correct it** to match the validated prototype,
do not just uncomment:

```yaml
waha:
  image: devlikeapro/waha:chrome      # NOT :latest
  platform: linux/amd64               # required on arm64 hosts
  restart: unless-stopped
  ports:
    - "127.0.0.1:3000:3000"           # host-local only
  environment:
    WAHA_API_KEY: ${WAHA_API_KEY}
    WHATSAPP_DEFAULT_ENGINE: WEBJS
    WHATSAPP_DOWNLOAD_MEDIA: "false"
    WAHA_DASHBOARD_USERNAME: admin
    WAHA_DASHBOARD_PASSWORD: ${WAHA_DASHBOARD_PASSWORD}
    WHATSAPP_SWAGGER_USERNAME: admin
    WHATSAPP_SWAGGER_PASSWORD: ${WAHA_DASHBOARD_PASSWORD}
  volumes:
    - ./.sessions:/app/.sessions      # pairing persistence

whatsapp-service:
  build:
    context: .
    dockerfile: apps/whatsapp-service/Dockerfile
  working_dir: /app/apps/whatsapp-service
  ports:
    - "8001:8000"                     # distinct from the pipeline's 8000
  environment:
    WAHA_URL: http://waha:3000
    WAHA_API_KEY: ${WAHA_API_KEY}
    WAHA_SESSION: default
  depends_on:
    - waha
```

Add the service-side keys to `.env.example` only (`WAHA_API_KEY`,
`WAHA_DASHBOARD_PASSWORD`); real `.env` is maintained by hand.

### Acceptance criteria

- [ ] `docker compose up` brings up `waha` and `whatsapp-service`; the WAHA
      dashboard is reachable at `127.0.0.1:3000` and the service index returns 200.
- [ ] The service image is a PHP image with no `pdo_pgsql`; the container holds no
      `packages/shared` or Doctrine dependency.
- [ ] The root `docker-compose.yml` WAHA service uses `devlikeapro/waha:chrome`,
      `platform: linux/amd64`, the `WEBJS` engine, and the `.sessions` volume.
- [ ] `.env.example` documents the new service keys; no real `.env` is committed.

### Blocked by

None - can start immediately.

---

## 2. WahaClient + WhatsApp pairing UI

### What to build

The `WahaClient` class — the PHP port of `waha.ts` — covering the session
lifecycle, and a Twig pairing page that drives it. `WahaClient` is the **only**
holder of the WAHA `X-Api-Key` and the only caller of WAHA (ADR 0002).

Session methods to port: `getSessionStatus` (404 → STOPPED), `startSession`
(treat 422 as already-started), `logoutSession`, and a QR fetch that proxies
WAHA's `/api/{session}/auth/qr?format=image` as an image stream.

The pairing page (Twig + a small inline `fetch` script, no build step): a
"Connect WhatsApp" button → `POST /session/start`; poll `GET /session` every ~2s;
when status is `SCAN_QR_CODE`, show the QR via the proxy route; when `WORKING`,
show connected state with a Logout button → `POST /session/logout`.

### Acceptance criteria

- [ ] Functional tests cover the status transitions against a mocked WAHA
      (404→STOPPED, SCAN_QR_CODE, WORKING) and the 422-on-start tolerance.
- [ ] The QR route streams WAHA's image with `Cache-Control: no-store`.
- [ ] **(manual)** Clicking Connect shows a scannable QR; scanning it with a phone
      moves the UI to WORKING; Logout returns to the disconnected state.
- [ ] The WAHA `X-Api-Key` appears only in `WahaClient`; it is never rendered to
      the page or exposed in a `WAHA_*` client variable.

### Blocked by

Slice 1.

---

## 3. Channel list + manual send form

### What to build

Extend `WahaClient` with `listOwnedChannels` (filter to `@newsletter` + role
`OWNER`/`ADMIN`) and `sendText(chatId, text)`. Add the **open**, host-bound
`POST /ui/send` route and a send form UI.

`/ui/send` enforces the guards server-side even though it is the human path:
`chatId` ends in `@newsletter`, `text` non-empty after trim. The form posts here
(not to the gated `/send`), so the human path needs no internal key (ADR 0002).

The send form: a channel dropdown populated from `GET /channels`, polling for ~20s
after pairing to absorb channel-sync lag; a textarea; a Send button; success
clears the textarea, failure keeps the input and shows the error.

### Acceptance criteria

- [ ] `listOwnedChannels` returns only `@newsletter` channels where the account is
      OWNER or ADMIN (covered by a unit test over a mocked WAHA payload).
- [ ] `POST /ui/send` rejects a non-`@newsletter` `chatId` and empty text with a
      clear error, before any WAHA call.
- [ ] The dropdown poll surfaces channels that appear within the budget and shows a
      no-channels state with a refresh otherwise.
- [ ] **(manual)** Selecting a channel, typing text, and clicking Send delivers a
      message that arrives in that WhatsApp channel.

### Blocked by

Slice 2.

---

## 4. Gated machine `/send` endpoint

### What to build

The JSON `POST /send` endpoint the pipeline calls. It accepts
`{ "chatId": "...@newsletter", "text": "..." }`, requires a matching
`X-Internal-Key` header, applies the same `@newsletter` + non-empty guards as
`/ui/send`, and routes to the **same** `WahaClient::sendText` in-process. A
missing/wrong key returns 401 before any guard or WAHA call.

Add `WHATSAPP_INTERNAL_KEY` to the service env and `.env.example`. This endpoint
shares all delivery logic with `/ui/send`; only the trust gate differs.

### Acceptance criteria

- [ ] With the correct `X-Internal-Key`, a valid body calls `WahaClient::sendText`
      and returns the WAHA result (verified against a mocked `WahaClient`).
- [ ] A missing or wrong `X-Internal-Key` returns 401 and never calls WAHA.
- [ ] A non-`@newsletter` `chatId` or empty text returns a 4xx with the same guard
      message as `/ui/send`.
- [ ] `/send` and `/ui/send` share one delivery path (no duplicated WAHA call).

### Blocked by

Slice 3.

---

## 5. Pipeline `WahaChannelPublisher` + review wiring

### What to build

The real `ChannelPublisher` that fills the seam, plus the review-page changes
around it. `WahaChannelPublisher` lives in `apps/pipeline` (it depends on Doctrine
and `PostedDeal`, so it cannot move to `packages/shared` — ADR 0003).

Behavior of `publish(PublishableDeal)`:

1. Precondition: `affiliateUrl` is present, else throw (the single product gate).
2. Format the German message — `{title}` / `12,99 €` / blank line / affiliate URL.
3. `POST {WHATSAPP_SERVICE_URL}/send` with the `X-Internal-Key`, `chatId =
   WHATSAPP_CHANNEL_ID`.
4. On 2xx: persist `PostedDeal(asin, snapshotPriceCents, now)` (defensive throw if
   `snapshotPriceCents` is null — it cannot be recorded).
5. On non-2xx / transport error: throw `PublishFailed`, persist nothing.

Review wiring: `PublishController` wraps `publish()` in try/catch — success marks
`publishRequestedAt` + flashes success; failure flashes the error and persists
nothing (button stays clickable). The template hides/disables Publish when
`affiliateUrl` is null. Swap the `services.yaml` alias
`ChannelPublisher → WahaChannelPublisher`. Add pipeline env
`WHATSAPP_SERVICE_URL`, `WHATSAPP_CHANNEL_ID`, `WHATSAPP_INTERNAL_KEY` (+
`.env.example`).

### Acceptance criteria

- [ ] A 2xx from a mocked gateway writes exactly one `PostedDeal(asin,
      snapshotPriceCents, now)` and sets `publishRequestedAt`.
- [ ] A non-2xx / transport error throws, writes no `PostedDeal`, leaves
      `publishRequestedAt` null, and the controller flashes the error.
- [ ] A deal with no `affiliateUrl` shows no Publish button, and a direct
      `POST /publish/{id}` for it is rejected without calling the gateway or writing.
- [ ] The formatted message body is `{title}\n{price} €\n\n{affiliateUrl}` with a
      comma-decimal German price.
- [ ] The only pipeline wiring change is the one `services.yaml` alias; the
      `when@test` alias still resolves.

### Blocked by

Slice 4.

---

## 6. Live end-to-end verification

### What to build

A manual smoke of the whole chain against real WAHA, a paired session, and a real
`@newsletter` channel — the one thing no test can prove. No new production code;
fix-forward anything the smoke surfaces.

### Acceptance criteria

- [ ] **(manual)** Clicking Publish on a recorded deal delivers the message to the
      configured channel, and the Amazon link renders a preview card.
- [ ] **(manual)** The delivered text matches the German format, untruncated.
- [ ] **(manual)** A `PostedDeal` row exists after the send, and on the next cycle
      the Already-Posted Guard suppresses that ASIN.
- [ ] **(manual)** A live `POST /send` without the `X-Internal-Key` is refused.

### Blocked by

Slices 5 and 2.

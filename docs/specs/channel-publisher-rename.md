# Channel-Agnostic Publisher Naming Cleanup

## Problem

The WAHA→whatsmeow swap (ADR 0005) replaced the WAHA container with a self-hosted
Go engine, but left WAHA's name scattered across code it no longer describes. The
PHP pipeline — the "brain" that should know nothing about *how* a deal reaches an
audience — still names the channel in its code: the publisher class is
`WahaChannelPublisher`, and the gateway it calls is full of a dead product name
(`App\Waha\WahaClient`, `WahaException`, a stray `WAHA_URL` env var that actually
points at the engine). A new developer reading `apps/pipeline` first meets "WAHA"
as load-bearing vocabulary for a product that is gone, and infers the pipeline is
WhatsApp-coupled when its `ChannelPublisher` seam is already channel-neutral.

The coupling is mostly cosmetic — the interface (`ChannelPublisher::publish(PublishableDeal)`)
names no channel — but the leftover names actively mislead, and one comment
(`.env.example:33`, "No WAHA_* vars") is now false because `WAHA_URL` still exists.

**Confidence:** data-backed
**Sources:** a repo-wide grep located every WAHA reference; the pipeline's
`ChannelPublisher`/`PublishableDeal` interfaces confirm the seam is already
channel-agnostic; ADR 0005 records that WAHA is gone.

## Solution

A naming + framing cleanup, not an architectural change. Behaviour, the `/send`
contract, message formatting, the trust boundary, and the "delivered ⇒ recorded"
invariant all stay byte-for-byte as they are. We rename in two zones, by altitude:

- **Pipeline (the brain) names the *transport*, not the channel.** The concrete
  publisher `WahaChannelPublisher` becomes `HttpChannelPublisher`: it implements
  `ChannelPublisher` by POSTing to a configured gateway over HTTP and knows
  nothing about WhatsApp. The same class could point at a different gateway by
  changing `serviceUrl`.

- **Gateway (`whatsapp-service`) names *WhatsApp*, since it genuinely is the
  WhatsApp adapter — it just drops the dead word WAHA.** `App\Waha\WahaClient` →
  `App\WhatsApp\WhatsAppClient`, `WahaException` → `WhatsAppException`;
  `SessionStatus`/`QrImage` keep their (already-neutral) names but move namespace.
  The dead `WAHA_URL` env var (which points at the engine) becomes `ENGINE_URL`.

We deliberately do **not** build a Telegram/other-channel adapter or any
multi-channel routing now: designing an abstraction against a single concrete
example invites the wrong seam. The interface is already the seam; a real second
channel will drive its shape later. The send payload keeps its current shape
(`{chatId, text, preview:{url,title,image}}`) — the `preview` block is generic
deal data a future channel adapter can simply ignore, so there is no reason to
reshape it now. Pipeline `WHATSAPP_*` env vars stay: they describe the real
`whatsapp-service` container deployed today, not a claim baked into the brain's
logic.

## Scope

### In scope

- As a new developer reading `apps/pipeline`, I find the publisher named for its
  transport (`HttpChannelPublisher`), so I don't infer a WhatsApp coupling that
  isn't there.
- As a maintainer, I find no "WAHA" anywhere in source (excluding regenerable
  `var/` caches), so the only product name in the code is the one still in use.
- As a maintainer of `whatsapp-service`, I find the engine client named for what
  it drives (`App\WhatsApp\WhatsAppClient`) and the engine URL env var named
  `ENGINE_URL`, so the wiring reads true.
- As the pipeline, I publish exactly as before: same `POST /send` body, same
  `X-Internal-Key`, same `PostedDeal` write on 2xx, same `PublishFailed` on error.
- As the operator, the pairing UI, the `/send` and `/ui/send` behaviour, the
  guards, and the status vocabulary are unchanged.

### Out of scope

- Any Telegram (or other channel) adapter, and any multi-channel routing or
  channel-selection logic. Deferred until a real second channel exists.
- Reshaping the `/send` payload or renaming the `preview` block.
- Moving message formatting (German price + emoji) — confirmed channel-neutral
  product copy; it stays in the publisher untouched.
- Renaming pipeline `WHATSAPP_*` env vars (`WHATSAPP_SERVICE_URL`,
  `WHATSAPP_CHANNEL_ID`, `WHATSAPP_INTERNAL_KEY`) — they describe the deployed
  container.
- Renaming the `apps/whatsapp-service` directory or the `whatsapp-service`
  compose service — it is the WhatsApp app.
- Any change to the Go `whatsmeow-engine` (it never used the WAHA name).
- Hand-editing the real `.env` (only `.env.example` is touched).

## Success Criteria

- `grep -ri waha apps packages docker-compose.yml .env.example` (excluding
  `*/var/*`) returns nothing.
- `apps/pipeline` contains `HttpChannelPublisher` (no `WahaChannelPublisher`);
  `services.yaml` binds `ChannelPublisher` → `HttpChannelPublisher`; the test is
  `HttpChannelPublisherTest`.
- `apps/whatsapp-service` exposes `App\WhatsApp\WhatsAppClient` /
  `WhatsAppException` under `src/WhatsApp/`; `services.yaml` injects
  `env(ENGINE_URL)` as the engine base URL; `docker-compose.yml` passes
  `ENGINE_URL` to the gateway.
- `.env.example`'s "No WAHA_* vars" note is true (no `WAHA_URL` remains).
- `phpunit`, `phpstan` (max), and `php-cs-fixer` pass in both PHP apps with no
  behavioural test changes — only renamed symbols/files.
- A live Publish still delivers a text + preview-card message to the configured
  channel and writes exactly one `PostedDeal` (manual smoke, unchanged path).

## Constraints

- PHP 8.4+ / Symfony 8 in both apps; PSR-4 means a namespace rename is a directory
  + `namespace` + every `use` site, kept consistent so autoloading holds.
- Regenerable `var/cache` and `var/phpstan` files contain "WAHA" but must not be
  hand-edited; they regenerate on the next build/analysis run.
- The `whatsapp-service` spec and ADR 0002 describe the gateway as the sole holder
  of channel credentials; renames must not alter that boundary.

## Open Questions / Risks

- **Low blast radius, but wide:** PSR-4 namespace moves and a class rename touch
  many files (use-statements, `services.yaml`, test references, Twig). The risk is
  a missed reference, not a design flaw — `phpstan` at max + the existing test
  suites are the safety net. Recommend committing the two zones (pipeline,
  gateway) separately so each stays independently revertable.
- No ADR needed: this is a reversible naming cleanup; the architectural decisions
  (engine swap, trust boundary) are already in ADRs 0001/0002/0005.

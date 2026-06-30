# Deal Promoter

Finds genuine Amazon deals via Keepa, re-confirms them live against the Amazon
Creators API, and publishes the affiliate-tagged links to a WhatsApp channel.
PHP 8.5 / Symfony 8 / Docker monorepo.

The chain runs end to end: discover → snapshot → record → review → **publish**.
Clicking *Publish* on a recorded deal posts the German-formatted affiliate
message to the channel and records it so it is never re-posted.

## Quick start

You only need Docker. Everything runs in containers.

```sh
cp .env.example .env   # fill in Keepa + Creators creds (WhatsApp keys optional until you publish)
make setup             # start containers, install deps (pipeline + gateway), migrate dev + test DBs
make cycle             # run one real Cycle (spends Keepa + Creators tokens)
```

Then open these two pages in your browser:

| Page | URL | What it's for |
|------|-----|---------------|
| **Deals review** | http://localhost:8000 | See the recorded deals and click *Publish* |
| **WhatsApp pairing** | http://localhost:8001 | One-time: click **Connect WhatsApp** and scan the QR to authenticate |

To publish, pair WhatsApp first (scan the QR at http://localhost:8001 with the
phone that owns the channel — it persists across restarts), then use the
*Publish* button on the review page.

Run `make` for the full list of targets.

| `make` target | Does |
|---------------|------|
| `setup` | First run: `up` + `install` + `migrate` + `migrate-test` |
| `up` / `down` / `restart` | Manage the stack |
| `install` | Composer install for pipeline **and** whatsapp-service |
| `migrate` / `migrate-test` | Run migrations on the dev / test database |
| `cycle` | Run one Cycle (`ARGS=-vv` by default) |
| `qa` | Full QA suite (phpunit + phpstan + cs-fixer) |
| `shell` / `logs` | Drop into the container / tail logs |

After adding a migration, run both `make migrate` and `make migrate-test` (or
just `make setup`) so dev and test schemas stay in lock-step — PHPUnit boots the
`test` database and fails against a stale schema.

`.env` needs a Keepa API key and Amazon Creators LWA credentials
(`CREATORS_CREDENTIAL_ID`/`SECRET`, `CREATORS_VERSION=3.2`, marketplace,
`AMAZON_PARTNER_TAG`). To publish it also needs `WHATSAPP_INTERNAL_KEY` (same
value on both pipeline and gateway) and `WHATSAPP_CHANNEL_ID` (the target
`@newsletter`). The engine is keyless. See `.env.example`.

## Layout

```
apps/pipeline/                 Symfony 8 app: the run-cycle command + review page
apps/whatsapp-service/         Symfony 8 gateway: the only thing that talks to the engine
apps/whatsmeow-engine/         self-hosted Go WhatsApp-Web engine (custom channel preview)
packages/shared/               cross-cutting integration code (seams in shared, impls app-side)
packages/creatorsapi-php-sdk/  vendored official Amazon Creators SDK v1.2.0 (path repo)
docs/                          product spec + API research briefs + ADRs
GLOSSARY.md                    canonical terms (Candidate, Pre-filter, Live Snapshot, ...)
docker-compose.yml             app + postgres + whatsmeow-engine + whatsapp-service
```

A monorepo: `apps/pipeline` requires `packages/shared` and the vendored SDK as
Composer **`path` repositories**, so everything resolves offline inside the
container. The gateway is deliberately standalone — it shares **no**
`packages/shared` and **no** database ([ADR 0001](docs/adr/0001-standalone-whatsapp-gateway.md)).

## How it works

A single Symfony Console command, `app:run-cycle`, runs one [Cycle](GLOSSARY.md):
**Discover** (Keepa `/deal`, ~150 Candidates) → **Pre-filter** (Criteria +
Outlier Guards, free) → **Already-Posted Guard** → **Live Snapshot** (Creators
`GetItems`/`OffersV2`, paid) → **Record** every price-valid survivor. It is
idempotent, run-locked (two Cycles never overlap), and fail-safe (any dependency
error skips the whole Cycle with no partial row).

The review page (`GET /`, port 8000) renders the latest Cycle's deals; each row
with an affiliate link has a *Publish* button that posts through the
`ChannelPublisher` seam to the live gateway. Publishing writes a `posted_deal`
row, which the Already-Posted Guard reads next Cycle to suppress a re-post.

For the detail — the funnel decisions, the `packages/shared` seam pattern, the
WhatsApp gateway and its trust boundary, tuning knobs, and the research that
shaped the code — read:

- [`GLOSSARY.md`](GLOSSARY.md) — canonical terms. **Read first.**
- [`docs/specs/product.md`](docs/specs/product.md) — the full vision.
- [`apps/pipeline/docs/specs/pipeline.md`](apps/pipeline/docs/specs/pipeline.md) — pipeline build spec.
- [`docs/adr/`](docs/adr/) — architecture decisions (standalone gateway, trust boundary, high-res preview, ...).
- [`docs/research/experiments-summary.md`](docs/research/experiments-summary.md) — what each throwaway probe proved.

## Quality

PHPUnit 13, PHPStan 2.x at `max`, PHP-CS-Fixer 3 (Symfony ruleset). All three
must pass.

```sh
make qa     # phpunit + phpstan + cs-fixer; or: make test / phpstan / cs
```

PHPUnit boots the **`test`** database, so it must be migrated first (`make setup`
or `make migrate-test`). Paid-API tests run against recorded fixtures; live
smokes are manual follow-ups, never merge blockers. The gateway mocks the engine
in tests (`docker compose exec whatsapp-service composer qa`); pairing and a real
channel delivery are verified by hand.
</content>
</invoke>

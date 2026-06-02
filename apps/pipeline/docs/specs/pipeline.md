# Deal Pipeline (PHP build)

## Problem

The deal-finding funnel has been proven end to end in throwaway TypeScript probes (`experiments/01`–`09`): Keepa discovery, the free Pre-filter, the Creators Live Snapshot, and the affiliate link all work against live amazon.de. But that code is disposable and not how the product ships. The product needs the same funnel rebuilt as a real, maintainable PHP/Symfony application: a headless **Deal Pipeline** that runs a [Cycle](../../../../GLOSSARY.md) and records what it found, plus a thin way for the operator to *see* those results and judge deal quality before any publishing exists.

This is the first deliverable of the build. It deliberately stops short of publishing to WhatsApp: that surface (a port of the validated `whatsapp-announcer` PoC) becomes its own PHP container later. The job here is to stand up the funnel, the datastore, and a review surface on solid foundations.

**Confidence:** data-backed
**Sources:** `experiments/` 01–09 proved the funnel against live amazon.de (`docs/research/experiments-summary.md`); the unproven half (does it earn) is out of scope here.

## Solution

Build the pipeline as a Symfony 8.x / PHP 8.5 application inside a monorepo, with cross-cutting integration code in a shared local Composer package so later apps reuse it.

A single Symfony Console command, `app:run-cycle`, runs one Cycle end to end:

1. **Discover** — Keepa `/deal` (one page, 150 raw [Candidates](../../../../GLOSSARY.md), 5 tokens).
2. **Pre-filter** (free, no API call) — editable [Criteria](../../../../GLOSSARY.md) config + the [Outlier Guards](../../../../GLOSSARY.md) (spike-polluted, no-demand, price-floor, absurd-claim), ported from the experiments.
3. **Already-Posted Guard** — suppress ASINs already in [Recorded Price History](../../../../GLOSSARY.md) (Postgres, behind a storage interface). Effectively a no-op until publishing exists, but wired now.
4. **Live Snapshot** — Creators `GetItems` (`OffersV2`) for **all** surviving candidates, batched 10 ASINs/call, with a configurable cap (default unlimited). Captures live buy-box price, availability, condition, merchant, `savings`/`savingBasisType`, `dealDetails`, `violatesMAP`, and the tagged `detailPageURL`.
5. **Record** — persist every found deal (Keepa signals + snapshot facts + raw discount signals) for this Cycle to Postgres.

No [Deal Gate](../../../../GLOSSARY.md) verdict is applied this session. The command records raw signals only; the publish-vs-skip dial is deferred until real rows can be eyeballed.

A minimal local web page (Symfony, served by the built-in server in the same container) reads the **latest** Cycle from Postgres and renders its found deals as a review table: per-row title, image, price, Keepa %, Amazon `savings` + `savingBasisType`, attestation flags, validity facts, and the affiliate link. Each row carries a **Publish button** that is a stub: it calls a `ChannelPublisher` interface whose only implementation logs/marks intent, providing the seam the future WhatsApp container wires into.

Integration clients live in `packages/shared`: a **hand-rolled Keepa client** (ported from `experiments/lib/keepa.ts`; no official SDK exists) and a `CreatorsClient` interface whose implementation wraps the **official Amazon Creators PHP SDK v1.2.0** (vendored as a Composer `path` repository). The wrapper keeps the pipeline depending on our own domain types, not the SDK's generated models.

## Scope

### In scope

- As the operator, I can run a single Cycle via `bin/console app:run-cycle` so a cron schedule can drive it later.
- As the system, I fetch 150 raw candidates from Keepa's `/deal` endpoint each Cycle.
- As the operator, I can define Criteria (discount %, price band, sales rank, categories, rating) in editable config so tuning thresholds needs no code change.
- As the system, I apply the Outlier Guards on the free Keepa payload to reject Price Outliers before any paid call.
- As the system, I suppress already-posted ASINs against Recorded Price History through a storage interface.
- As the system, I take a Live Snapshot of every surviving candidate via the official Creators SDK (batched 10/call, configurable cap) for live price, availability, condition, merchant, savings, and attestation.
- As the system, I record every found deal of a Cycle (Keepa signals + snapshot facts + raw discount signals) to Postgres.
- As the operator, I can open a local web page that lists the latest Cycle's found deals with their raw signals and affiliate links, so I can judge deal quality.
- As the operator, I see a Publish button per deal that calls a stubbed `ChannelPublisher` seam, so the future WhatsApp container has a defined hook.
- As the system, the Cycle holds a run-lock so a future cron tick cannot overlap a slow Cycle.
- As a future maintainer, I find marketplace, channel, and affiliate tag modeled as configuration, and the integration clients in a shared Composer package the later apps reuse.

### Out of scope

- **Publishing to WhatsApp.** No channel publisher, no send, no `whatsapp-announcer` port this session. The Publish button is a stub. (Future: its own PHP container.)
- **The Deal Gate verdict.** No publish/skip decision and no volume-vs-trust dial this session; record raw signals only and decide after eyeballing data.
- **Scheduling infrastructure.** No cron/scheduler container and no Symfony Scheduler worker; Cycles are run manually. The run-lock is built in so cron drops in later.
- **Pacing, Repost Policy, alerting (Telegram), post-text templates.** All depend on publishing, which is out.
- **The deep `/product?stats` confirmation stage.** Dropped (exp05/09): 0/26 rejections, cannot un-pollute history.
- **Multiple marketplaces/channels at runtime.** amazon.de only, modeled as config.
- **Any admin UI, landing page, or analytics.** The review page is a read-only table, not an app.

## Success Criteria

- `bin/console app:run-cycle` runs unattended end to end: Keepa fetch → Pre-filter → Already-Posted Guard → Live Snapshot → record, with no manual steps and no published post.
- A Cycle persists every found deal with its Keepa signals, snapshot facts, and raw discount signals to Postgres.
- The web page renders the latest Cycle's found deals with correct prices (integer cents at the boundary, never compared as floats), working affiliate `detailPageURL`s, and the raw signals needed to judge quality.
- The Already-Posted Guard runs against Recorded Price History through the storage interface (demonstrably suppresses a seeded ASIN).
- A second concurrent `app:run-cycle` is blocked by the run-lock.
- The Creators integration uses the official SDK v1.2.0 behind the `CreatorsClient` interface; swapping the implementation requires no pipeline change.
- Criteria changes via config take effect on the next Cycle with no code change.
- `phpunit`, `phpstan` (max), and `php-cs-fixer` all pass.

## Constraints

- **Stack:** PHP 8.5 (8.4 minimum, the floor Symfony 8 requires), Symfony 8.x (`^8.0`; pin toward the 8.4 LTS when it lands, since 8.0 has a short support window). Datastore is a local Postgres container; persistence via Doctrine ORM 3 / DBAL 4 behind a storage interface. Run-lock via the Symfony Lock component (Postgres store).
- **Repo:** monorepo — `apps/pipeline` (this app) + `packages/shared` (Keepa client, `CreatorsClient` interface + SDK-backed impl, storage interface) wired as a Composer `path` repository. The official Creators SDK v1.2.0 is vendored under `packages/` as a `path` repo (it ships as a download, not on Packagist).
- **Containers this session:** `app` (Symfony built-in server, runs both the console command and the page) + `postgres`. whatsapp-service remains a documented future placeholder in the compose file. One root `docker-compose`.
- **APIs:** Keepa metered by tokens — Pre-filter hard on the cheap `/deal` call; deep-fetch only surviving candidates. Creators is the PA-API successor; affiliate links must be the API-provided `detailPageURL`. Credential is v3.2 → LWA (the SDK handles the auth fork natively).
- **Boundaries:** `price.money.amount` is a decimal euro; convert to integer cents at the edge and never cross the Keepa-cents / Creators-euros boundary unconverted. Never compare Keepa `avg*` to `stats.*` with `===` — use a tolerance.
- **Tooling:** PHPUnit 13 (requires PHP 8.4+), PHPStan 2.x at `max` level (Symfony extension), PHP-CS-Fixer 3 (Symfony ruleset). `bun` (not `npm`) for any JS tooling. Secrets via `.env` (only `.env.example` is committed/edited).

## Open Questions / Risks

- **Deal Gate dial (deferred, not resolved).** Strict (Amazon Attestation only) vs Loose (price drop) vs Middle (trust-tiered, advertise conservative `min(keepa, amazon)`, headline % only on Attestation). Decide next session after reviewing real recorded rows. The persistently-polluted Keepa `avg90` and the gameable Amazon `LIST_PRICE` mean only Amazon Attestation (`dealDetails` / `WAS_PRICE`) gives a trustworthy Discount Magnitude — rare (~1/10 in exp09).
- **Snapshot cap default (unlimited)** is fine on the entry token tier for normal feeds, but a pathological survivor count would fan out GetItems calls; the configurable cap is the throttle.
- **Official SDK ergonomics.** The SDK is OpenAPI-generated and verbose, and depends on Guzzle 7 (the app otherwise uses Symfony HttpClient). Both are isolated behind the `CreatorsClient` wrapper, but the vendored `path`-repo setup must keep Docker builds offline-reproducible.

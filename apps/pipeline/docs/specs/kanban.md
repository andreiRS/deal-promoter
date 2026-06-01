# Deal Pipeline — Kanban

Vertical slices for the build specced in [`pipeline.md`](pipeline.md). Each slice is independently grabbable, verifiable on its own, and listed in dependency order. Move cards between columns as work progresses (edit the lists below).

All slices are **unattended** (decisions were resolved up front; the only deferred call, the Deal Gate dial, is out of scope this session). Slices touching live paid APIs are verified against recorded fixtures from `experiments/out/`; a one-time live smoke is a manual follow-up, never a merge blocker.

## Board

### 📋 To Do

- [ ] **P8** — Review web page
- [ ] **P9** — Publish button stub + `ChannelPublisher` seam

### 🚧 In Progress

_(none)_

### ✅ Done

- [x] **P1** — Monorepo + Docker skeleton (`70dc85b`)
- [x] **P3** — Keepa client (hand-rolled) (`7bd7df3`, baseline QA fix `cc1b35a`)
- [x] **P4** — Pre-filter: Criteria + Outlier Guards (`3b83e93`)
- [x] **P2** — Postgres + Doctrine + storage interface + schema (`3996f2f`)
- [x] **P5** — Creators SDK + `CreatorsClient` + Live Snapshot (`7f0ab9b`)
- [x] **P6** — Already-Posted Guard (`b0804bd`)
- [x] **P7** — `app:run-cycle` orchestration + run-lock + record (`a190343`)

## Dependency order

```
P1 ─┬─ P2 ─┬─ P6 ──┐
    │      │       │
    ├─ P3 ─┴─ P4 ──┼─ P7 ── P8 ── P9
    │              │
    └─ P5 ─────────┘
```

P1 unblocks everything. P2/P3/P5 can run in parallel after P1. P7 is the convergence point; P8 and P9 are the web surface on top.

---

## P1 — Monorepo + Docker skeleton

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

The repo foundation everything else builds on: a `apps/pipeline` Symfony 8.x / PHP 8.5 application (PHP 8.4 is the floor Symfony 8 requires), a `packages/shared` local Composer package wired as a `path` repository, one root `docker-compose` with an `app` service (Symfony built-in server) and a `postgres` service, plus documented placeholder stubs for the future `waha` / `whatsapp-service`. `.env.example` lists every secret (`KEEPA_API_KEY`, Creators LWA credential id/secret/version, `AMAZON_PARTNER_TAG`, marketplace). QA tooling (PHPUnit 13, PHPStan 2.x `max` + Symfony extension, PHP-CS-Fixer 3 Symfony ruleset) is installed and green on an empty project.

### Acceptance criteria

- [ ] `docker compose up` starts `app` + `postgres`; `bin/console` lists commands inside the `app` container.
- [ ] `packages/shared` is required by `apps/pipeline` via a Composer `path` repository and autoloads.
- [ ] `.env.example` is committed with all required keys; no real `.env` is committed.
- [ ] `vendor/bin/phpunit`, `vendor/bin/phpstan analyse` (max), and `vendor/bin/php-cs-fixer fix --dry-run` all pass.
- [ ] Future `waha` / `whatsapp-service` placeholders are present in compose as commented/disabled stubs with a note pointing at the future port.

### Blocked by

None - can start immediately.

---

## P2 — Postgres + Doctrine + storage interface + schema

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

Doctrine ORM 3 / DBAL 4 wired to the Postgres container, with migrations. The schema models a Cycle and its found deals: `cycle_run` (started/finished, summary counts) and `found_deal` (Keepa signals + snapshot facts + raw discount signals, FK to the run), plus a reserved `posted_deal` table for Recorded Price History (unused until publishing exists). A storage interface fronts the Recorded-Price-History queries so the query layer stays swappable and testable.

Money is stored as integer cents; conversion happens at the edge.

```
cycle_run(id, started_at, finished_at, raw_count, surviving_count, snapshotted_count)
found_deal(id, cycle_run_id, asin, title, image_url,
           keepa_avg90_cents, keepa_drop_pct,
           snapshot_price_cents, availability, condition, merchant_id,
           amazon_savings_pct, saving_basis_type, has_deal_details, violates_map,
           affiliate_url, created_at)
posted_deal(id, asin, price_cents, posted_at)   -- reserved, written by future publish
```

### Acceptance criteria

- [ ] Migrations create `cycle_run`, `found_deal`, `posted_deal`; `doctrine:migrations:migrate` runs clean against the Postgres container.
- [ ] A `RecordedPriceHistory` storage interface exists with a Doctrine-backed implementation; consumers depend on the interface.
- [ ] An integration test persists and reads back a `cycle_run` with `found_deal` rows.
- [ ] Money columns are integer cents; no float price columns exist.

### Blocked by

P1.

---

## P3 — Keepa client (hand-rolled)

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

A Keepa client in `packages/shared` (Symfony HttpClient), ported from `experiments/lib/keepa.ts`: fetch one `/deal` page (150 raw candidates, 5 tokens), decode the documented quirks (image char-code array → CDN filename, Keepa-time conversion, 2D `[dateRange][priceType]` deal arrays, sentinels `-1`/`-2`/`0`), and expose parsed Candidate DTOs. Token meter read off the JSON body. No publishing of raw candidates as deals; this is discovery only.

### Acceptance criteria

- [ ] Given a recorded `/deal` fixture from `experiments/out/`, the client returns parsed Candidate DTOs with correct title, price (cents), avg90, salesRankDrops, and decoded image URL.
- [ ] Sentinel values (`-1` current, `-2`/`0` avg/delta) are handled, not surfaced as real prices.
- [ ] Keepa `avg*` vs `stats.*` comparisons use a cents tolerance, never `===`.
- [ ] Unit tests pass against fixtures (unattended).
- [ ] [manual] Live smoke against the real Keepa key returns ~150 candidates once keys are present.

### Blocked by

P1.

---

## P4 — Pre-filter: Criteria + Outlier Guards

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

The free, no-API-call Pre-filter over Keepa Candidates: editable **Criteria** config (discount %, price band, sales rank, categories, rating) plus the four **Outlier Guards** (spike-polluted-baseline `avg90 > 3×avg30`, no-demand `salesRankDrops90 < 1`, abs-price-floor `< €2`, absurd-claim `> 97%`). A Candidate becomes a surviving candidate only if it matches Criteria and passes all Guards. Thresholds live in config and take effect with no code change.

### Acceptance criteria

- [ ] Given a Keepa fixture, the Pre-filter produces the expected surviving set; each rejection records which Criterion/Guard fired.
- [ ] All four Outlier Guards run on the `/deal` payload alone (no `/product` call).
- [ ] Changing a Criteria threshold in config changes the surviving set with no code change.
- [ ] Unit tests cover each Guard and each Criterion boundary.

### Blocked by

P3.

---

## P5 — Creators SDK + `CreatorsClient` + Live Snapshot

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

Vendor the official Amazon Creators PHP SDK v1.2.0 under `packages/` as a Composer `path` repository. Define a `CreatorsClient` interface in `packages/shared` and an implementation that wraps the SDK. The implementation takes surviving candidates' ASINs, calls `GetItems` (`OffersV2`) batched 10 ASINs/call with a configurable cap (default unlimited), selects the buy box (`isBuyBoxWinner === true`, never `listings[0]` or min-price), and returns Live Snapshot DTOs: price (cents), availability, condition, merchant id, `savings`/`savingBasisType`, `dealDetails`, `violatesMAP`, and the tagged `detailPageURL`. The pipeline depends on our DTOs, not the SDK's generated models. v3.2/LWA auth is handled by the SDK.

### Acceptance criteria

- [ ] SDK is vendored via a `path` repo; `composer install` resolves it offline in the Docker build.
- [ ] `CreatorsClient` interface exists; the pipeline depends on the interface, not the SDK.
- [ ] Given recorded GetItems fixtures, the wrapper returns Snapshot DTOs with the buy-box listing selected and attestation flags (`dealDetails`, `WAS_PRICE`) parsed.
- [ ] Batching chunks ASINs at 10/call; the configurable cap is respected.
- [ ] Price is converted to integer cents at the boundary; the affiliate `detailPageURL` (with `tag=`) is passed through verbatim.
- [ ] [manual] Live smoke against the real v3.2 credential returns a snapshot once keys are present.

### Blocked by

P1.

---

## P6 — Already-Posted Guard

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

The check that suppresses a Candidate whose ASIN already appears in Recorded Price History, via the storage interface from P2. Governed by a (still minimal) Repost Policy hook. Effectively a no-op until publishing writes `posted_deal` rows, but fully wired and tested now so it is correct the moment publishing lands.

### Acceptance criteria

- [ ] Given a seeded `posted_deal` row, the Guard removes that ASIN from the surviving set.
- [ ] With an empty `posted_deal` table, the Guard passes everything through.
- [ ] The Guard reads through the `RecordedPriceHistory` interface, not Doctrine directly.
- [ ] Unit/integration tests cover suppress and pass-through.

### Blocked by

P2.

---

## P7 — `app:run-cycle` orchestration + run-lock + record

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

The Symfony Console command `app:run-cycle` that runs one Cycle end to end: Keepa discover (P3) → Pre-filter (P4) → Already-Posted Guard (P6) → Live Snapshot for all survivors (P5) → record every found deal + cycle summary to Postgres (P2). No Deal Gate verdict. A run-lock (Symfony Lock component, Postgres store) prevents overlapping runs. Fail-safe: an item or the cycle skips on a dependency error rather than producing a bad record.

### Acceptance criteria

- [ ] `bin/console app:run-cycle` runs the full funnel unattended and persists a `cycle_run` with its `found_deal` rows (fixture-backed integration test).
- [ ] A second concurrent `app:run-cycle` is rejected by the run-lock.
- [ ] A simulated Keepa/Creators error skips cleanly (no partial/corrupt cycle) and the command exits non-zero.
- [ ] No publish/skip verdict is computed or stored; raw signals only.
- [ ] [manual] One real end-to-end run records live rows once keys are present.

### Blocked by

P2, P3, P4, P5, P6.

---

## P8 — Review web page

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

A minimal Symfony controller + template, served by the built-in server in the `app` container, that reads the **latest** `cycle_run` and renders its `found_deal` rows as a read-only review table: title, image, price, Keepa %, Amazon `savings` + `savingBasisType`, attestation flags (`dealDetails`/`WAS_PRICE`), availability/condition/merchant, and the affiliate link. No verdict column. No sorting — rows render in their natural recorded order.

### Acceptance criteria

- [ ] `GET /` renders the latest cycle's found deals from Postgres; an empty DB shows an empty-state, not an error.
- [ ] Each row shows the raw signals needed to judge quality and a working affiliate `detailPageURL`.
- [ ] Prices render from integer cents (no float formatting bugs).
- [ ] The page reads only; it does not trigger a cycle.

### Blocked by

P2, P7.

---

## P9 — Publish button stub + `ChannelPublisher` seam

**Parent:** [`pipeline.md`](pipeline.md)

### What to build

A per-row **Publish button** on the review page and the seam it hooks: a `ChannelPublisher` interface in `packages/shared` with a Null/Logging implementation for now. Clicking the button POSTs to an endpoint that calls `ChannelPublisher::publish(deal)`, which logs and marks publish intent on the row. This is the defined hook the future WhatsApp container's HTTP publisher implementation drops into with no page change.

### Acceptance criteria

- [ ] A `ChannelPublisher` interface exists with a `NullChannelPublisher` (logs + marks intent) as the only implementation.
- [ ] Clicking Publish POSTs to an endpoint that calls the publisher; the row reflects "publish requested".
- [ ] No real WhatsApp/WAHA call is made; swapping in a real implementation needs no controller or template change.
- [ ] The endpoint is covered by a functional test.

### Blocked by

P8.

# API experiments

Throwaway research probes to **confirm the external APIs** behind Deal Promoter and
**collect learnings** before building the production clients. Production is
PHP/Docker, so these TypeScript probes exist for fast iteration only; the learnings
(auth, query shapes, response shapes, token/transaction costs, decoding) port
straight to PHP.

Two APIs live here, each with its own backing research brief, its own client in
`lib/`, and its own numbered experiments. Numbering is one shared sequence:
**01–05 = Keepa**, **06+ = Amazon Creators**.

## Setup

```sh
cd experiments
cp .env.example .env        # then fill in your keys/credentials
bun run 01-key-and-tokens/run.ts
```

`bun` auto-loads `.env`. No install step is needed for the probes that only use
`fetch` and the shared `lib/`.

## Layout

- `lib/keepa.ts` — Keepa client + decoders (Keepa-time, delta-CSV, token meter).
- `lib/creators.ts` — Amazon Creators client (OAuth 2.0 token cache + GetItems).
- `NN-name/` — one folder per experiment. Each has a `run.ts` (the probe) and a
  `FINDINGS.md` (what the live API actually returned vs. what the brief claimed).
- `NN-name/sample.*.json` — a small, **committed** trimmed sample of that
  experiment's API response, so you can see the real shape without re-calling or
  re-reading the docs. The full raw `out/*.dump.json` stay gitignored (multi-MB,
  stale snapshots, redistribution ToS).

---

## Keepa API

Sources price-drop candidates (step 1 of the pipeline). Backing brief:
[`../docs/research/keepa.md`](../docs/research/keepa.md). Client: `lib/keepa.ts`.

In `sample.product.json` the `csv` series are truncated to the last 3 points and
long `variations` lists are capped (see the `_csv_note` / `_variations_note` keys);
`stats` is kept whole. Each sample is one illustrative record: `02` a
persistent-baseline glitch (the HDMI cable), `03` the reference product+`stats`
shape, `04` a recent-*spike* glitch (TV stand, avg90 ≫ avg30), `05` a genuine
demand-led survivor (Bosch sensor).

| # | Folder | Question it answers | Token cost |
|---|--------|---------------------|-----------|
| 01 | `01-key-and-tokens` | Does the key work? What's the live token meter? | 0 (free) |
| 02 | `02-deals` | Deal-query shape + response shape for amazon.de | 5/page (flat, confirmed) |
| 03 | `03-product` | /product + `stats`, CSV/time decoding on a real ASIN | 1/ASIN (confirmed; stats free) |
| 04 | `04-glitch-guard` | Which `stats`-based bounds separate real deals from price-glitch artifacts (the `sortType=4` junk exp02 flagged)? | 5/page + 1/candidate (confirmed) |
| 05 | `05-funnel-dryrun` | End-to-end `/deal` → filter → batched `/product+stats`: survivors/page, total tokens, throughput vs 20/min refill | 5/page + 1/survivor (confirmed; 31/pass, 26 survivors of 150 on a raw sortType=4 page) |

---

## Amazon Creators API

Validates surviving candidates against live Amazon and supplies the affiliate link
(step 3 of the pipeline). Backing brief:
[`../docs/research/creators-api.md`](../docs/research/creators-api.md). Client:
`lib/creators.ts` (OAuth 2.0 client-credentials + cached bearer token; the PHP port
target is the official `thewirecutter/paapi5-php-sdk` v2.x).

Env (in `.env.example`): `CREATORS_CREDENTIAL_ID`, `CREATORS_CREDENTIAL_SECRET`,
`CREATORS_VERSION` (2.x = Cognito, 3.x = Login with Amazon), `CREATORS_MARKETPLACE`,
`CREATORS_PARTNER_TAG`.

| # | Folder | Question it answers | Cost |
|---|--------|---------------------|------|
| 06 | `06-creators-auth` | Which auth path does our Version imply? What does the OAuth token exchange return, and does the token cache? | ~0 (token endpoint rate-limited, not metered) |
| 07 | `07-creators-getitems` | GetItems request + `ItemsResult`/`Errors` shape: top-level ASIN + tagged `DetailPageURL`, invalid ASIN → `Errors[]` | 1 transaction |

---

## Discipline (so we don't burn tokens / trip rate limits)

- **Keepa:** every probe logs the live token meter (`tokensLeft`, `refillRate`,
  `refillIn`) before and after each call. Read it; back off when low. Never request
  `offers`/extra data unless a probe specifically tests it.
- **Creators:** one call = one transaction regardless of batch size; cache the
  bearer token (never fetch per request); never commit the `access_token`.
- Capture raw responses to `NN-name/out/*.dump.json` (gitignored) for offline
  inspection so we don't re-call to re-read.
- Record every surprise in the experiment's `FINDINGS.md` — that's the actual
  deliverable feeding back into the research briefs under `docs/research/`.

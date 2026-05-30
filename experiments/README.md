# Keepa API experiments

Throwaway research probes to **confirm the Keepa API** and **collect learnings**
before building the production client. Production Deal Promoter is PHP/Docker, so
these TypeScript probes exist for fast iteration only; the learnings (token
costs, query shapes, response shapes, time/CSV decoding) port straight to PHP.

Backing research brief: [`../docs/research/keepa.md`](../docs/research/keepa.md).

## Setup

```sh
cd experiments
cp .env.example .env        # then paste your KEEPA_API_KEY into .env
bun run 01-key-and-tokens/run.ts
```

`bun` auto-loads `.env`. No install step is needed for the probes that only use
`fetch` and the shared `lib/`.

## Layout

- `lib/keepa.ts` — thin shared client + decoders (Keepa-time, delta-CSV, token
  meter). Reference port target for the PHP client.
- `NN-name/` — one folder per experiment. Each has a `run.ts` (the probe) and a
  `FINDINGS.md` (what the live API actually returned vs. what the brief claimed).

## Experiments

| # | Folder | Question it answers | Token cost |
|---|--------|---------------------|-----------|
| 01 | `01-key-and-tokens` | Does the key work? What's the live token meter? | 0 (free) |
| 02 | `02-deals` | Deal-query shape + response shape for amazon.de | 5/page (flat, confirmed) |
| 03 | `03-product` | /product + `stats`, CSV/time decoding on a real ASIN | 1/ASIN (confirmed; stats free) |
| 04 | `04-glitch-guard` | Which `stats`-based bounds separate real deals from price-glitch artifacts (the `sortType=4` junk exp02 flagged)? | 5/page + 1/candidate (confirmed) |
| 05 | `05-funnel-dryrun` | End-to-end `/deal` → filter → batched `/product+stats`: survivors/page, total tokens, throughput vs 20/min refill | ~5/page + 1/survivor |

## Discipline (so we don't burn tokens)

- Every probe logs the live token meter (`tokensLeft`, `refillRate`, `refillIn`)
  before and after each call. Read it; back off when low.
- Never request `offers`/extra data unless a probe specifically tests it.
- Capture raw responses to `NN-name/out/*.dump.json` (gitignored) for offline
  inspection so we don't re-call to re-read.
- Record every surprise in the experiment's `FINDINGS.md` — that's the actual
  deliverable feeding back into `docs/research/keepa.md`.

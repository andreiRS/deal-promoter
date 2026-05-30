# Experiment 04 — glitch-guard (stats-based bounds vs price-glitch artifacts)

**Question:** Which `stats`-based bounds separate genuine deals from the
price-glitch artifacts that `sortType=4` (PERCENT_DELTA) floats to the top?
**Chain:** `GET /deal` (sortType 4, dateRange 90d) → `GET /product?stats=90` on
the top 25 candidates. **Run date:** 2026-05-30 · **Domain:** amazon.de (3)

## Result: PASS

11/11 recipe-invariant checks passed. A concrete filter + ranking recipe is
defined in `run.ts` (`GUARD` constants + `glitchGuard()`) and validated against a
live, deliberately-glitchy feed: **of the top 25 by claimed % drop, only 4
survived (21 rejected).** The single most important finding is below (the
verified-drop signal is itself unsafe without the spike guard).

### Token cost — CONFIRMED, no surprises
`/deal` page = **5** (`tokensConsumed: 5`, 1200→1195); `/product` batch of 25 =
**25** (1195→1170). `stats=90` still free. Total 30 tokens for a full
deal→survivor pass. `refillRate: 20/min`. README row 04's "~5/page + 1/survivor"
holds exactly.

## The recipe (port this to the PHP ranker)

Two prices, one divergence:
- **claimed drop** = `deal.deltaPercent[90d][AMAZON]` — the headline. Computed by
  Keepa against the *deal endpoint's own weighted avg*, which is glitch-polluted.
- **verified drop** = `(stats.avg90 − stats.current) / stats.avg90` from the
  trustworthy `/product` `stats` (exp03).

A candidate is **REJECTED** if any bound trips (constants in `run.ts`):

| Bound | Rule | Catches |
|------|------|---------|
| `abs-price-floor` | `current < €2,00` | sub-€2 cable/accessory glitches |
| `spike-polluted-baseline` | `avg90 > 3 × avg30` | a transient price spike inflated the 90d baseline |
| `below-floor-glitch` | `current < 0.5 × all-time-min` | implausible underprice (e.g. €0,79 vs €181 baseline) |
| `weak-real-drop` | `verified < 20%` | claimed drop not corroborated by stats |
| `claim-divergence` | `claimed% − verified% > 25 pts` | polluted deal baseline (the core glitch signature) |
| `absurd-claim` | `claimed% > 97` | near-100% drops are almost always glitches |
| `thin-data-oos` | `outOfStockPercentage90 > 80` | thin/unreliable history |
| `no-demand` | `salesRankDrops90 < 1` | real price but nobody buys it (still noise) |

Survivors are **ranked** by `verified_drop × ln(1 + salesRankDrops90) ×
(1 − oos90/100)` — real discount × demand, penalised by out-of-stock time.

## What the live feed showed (25 candidates, top of the 90d %-delta feed)

- **KEEP 4 / REJECT 21.** ~84% of the glitchy feed's top was junk — confirms
  exp02's warning that ranking on `deltaPercent` alone floats garbage.
- **claimed drop** spanned 12–99% (median **83%**); **verified drop** (recomputed
  from stats) spanned 9–100% (median **61%**); **divergence** −23 to +84 pts
  (median **21**). Many candidates sit right at the 25-pt divergence line, so this
  threshold is the main precision/recall dial.
- **Survivors' real drops: 49%, 42%, 35%, 28%** — all corroborated by stats,
  demand-gated, non-spiked. These are the deals production would actually publish.
- **Reject-reason histogram** (a deal can trip several):
  `claim-divergence 18 · spike-polluted-baseline 15 · weak-real-drop 9 ·
  no-demand 8 · below-floor-glitch 6 · abs-price-floor 4 · thin-data-oos 1`.
- **Negative divergence is a signal too:** a few candidates had verified > claimed
  (divergence down to −23) — genuine deals the *deal endpoint under-reported*
  because its own polluted avg dragged the headline % down. The stats-recomputed
  drop recovers them. (One such became the top survivor.)

## THE important finding — `stats.avg90` is itself glitch-polluted

**15 of 25 candidates had `avg90 > 3 × avg30`.** The exact transient spikes that
pollute the *deal* endpoint's weighted average also pollute the `/product`
`stats.avg90`. So the "verified drop" — which divides by `avg90` — is **not
self-sufficient**: on a spiked product it reports an inflated "real" discount and
would wave a glitch straight through. The `spike-polluted-baseline` guard
(`avg90` vs the much-more-stable `avg30`) is therefore **load-bearing, not
defence-in-depth** — it invalidates the verified-drop math precisely where that
math is wrong.

**Port recommendations:**
1. Always pair the verified-drop computation with the spike guard. Never trust a
   discount derived from `avg90` alone.
2. Consider a **more robust baseline** for the drop itself in production — e.g.
   `min(avg30, avg90)` or a median of `{avg30, avg90}` — so a single spike can't
   inflate the denominator. Worth A/B-ing in exp05.
3. `avg30` is the stabler reference here; `avg90` is the spike-catcher. Keep both.

## Decoding landmines re-confirmed this run
- **Deal arrays are 2D `[dateRange][priceType]`, NOT `[priceType][dateRange]`**
  (exp02). `deal.deltaPercent[90d][AMAZON]` = `[3][0]`. `current` is 1D
  `[priceType]`. Getting this backwards silently reads SALES_RANK as a % drop —
  fixed a first-draft bug here, flag loudly for the PHP port.
- **`min`/`max` are all-time; `minInInterval`/`maxInInterval` are the windowed
  extremes** (exp03). The floor guard deliberately uses all-time `min` as the hard
  underprice floor.
- Deal `deltaPercent` no-data sentinels are `-2`/`0` (filtered by `> 0`); stats
  arrays use `-1` (filtered by `statField`'s `>= 0`).

## Open / deferred
- **Thresholds are first-pass.** Tuned to one amazon.de feed on 2026-05-30. The
  25-pt divergence and 20% verified-drop dials want a larger sample before they're
  locked. exp05 (funnel dry-run) sees more pages and can report stable rates.
- Precision/recall is **qualitative**: no ground-truth labels, so "84% junk" is
  the guard's reject rate on a worst-case feed, not a measured false-positive rate.
- A robust-baseline variant (rec #2) is untested — leave for exp05/production.

## Files
- `run.ts` — the live probe (2 calls, 30 tokens, 11 ✓/✗ invariant checks).
- `analyze.ts` — offline re-analysis of the dumps (no API calls); recomputes the
  verdict table so the recipe can be re-tuned without re-spending tokens.
- `out/deal-page.dump.json`, `out/product-batch.dump.json` — raw responses
  (gitignored).

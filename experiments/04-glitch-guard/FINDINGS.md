# Experiment 04 — glitch-guard (stats-based bounds vs price-glitch artifacts)

**Question:** Which `stats`-based bounds separate genuine deals from the
price-glitch artifacts that `sortType=4` (PERCENT_DELTA) floats to the top?
**Chain:** `GET /deal` (sortType 4, dateRange 90d) → `GET /product?stats=90` on
the top 25 candidates. **Run date:** 2026-05-30 · **Domain:** amazon.de (3)

## Result: PASS

11/11 recipe-invariant checks passed. A concrete filter + ranking recipe is
defined in `run.ts` (`GUARD` constants + `glitchGuard()`) and validated against a
live, deliberately-glitchy feed: **of the top 25 by claimed % drop, only 2
survived (23 rejected).** Two findings below upend the recipe's original premise
and matter for the funnel design.

### Token cost — CONFIRMED
`/deal` page = **5** (1200→1195); `/product` batch of 25 = **25** (1195→1170).
`stats=90` free. Total **30 tokens** for a full deal→survivor pass.
`refillRate: 20/min`. README row 04's "5/page + 1/candidate" holds exactly.

## FINDING 1 — the "claimed vs verified drop" divergence is DEAD on arrival

The recipe was built on a "two drops, one divergence" thesis: compare the deal's
**claimed** drop (`deal.deltaPercent[90d][AMAZON]`) to a **verified** drop
recomputed from `/product` stats (`(stats.avg90 − stats.current)/stats.avg90`),
and reject big divergences. **Divergence was exactly 0 for all 25 candidates.**

Keepa computes the deal's 90d `deltaPercent` from the *same* 90d weighted average
that `/product` `stats.avg90` reports — they are the same number by construction.
So recomputing the drop from `/product` adds **zero information**, and the
`claim-divergence` guard fired **0 times**. A glitch inflates the deal average and
the stats average identically, so they can never disagree.

**Port consequence:** drop the divergence check. The deal payload's
`deltaPercent[90d]` already *is* the stats-avg90 drop.

## FINDING 2 — glitches are caught structurally, and mostly from the /deal payload alone

With divergence useless, the workhorses (reject reason histogram; a deal can trip
several) were:

| reason | hits | rule |
|--------|------|------|
| `no-demand` | 10 | `salesRankDrops90 < 1` |
| `spike-polluted-baseline` | 10 | `avg90 > 3 × avg30` |
| `abs-price-floor` | 8 | `current < €2,00` |
| `absurd-claim` | 7 | `claimed% > 97` |
| `thin-data-oos` | 1 | `outOfStockPercentage90 > 80` |
| `below-floor-glitch` | 0 | `current < 0.5 × all-time-min` (never needed) |
| `weak-real-drop` | 0 | `verified < 20%` (never needed at the top of a %-sorted feed) |

All 23 rejects were caught by the top four; the all-time-min floor and the
weak-drop guard never fired on this feed.

**The big one for funnel economics:** every signal those four guards need is
already in the **/deal payload** — `current`, `deltaPercent[90d]` (the drop),
`avg` (2D `[dateRange][priceType]`, carrying both the ~30d `MONTH` and the `90d`
weighted averages → the spike ratio), and `salesRankDrops90`. Only
`thin-data-oos` (`outOfStockPercentage90`, fired 1×) and the never-fired
all-time-min floor actually require a `/product` call. So the glitch-guard can run
as a **pre-filter on the cheap /deal page (5 tokens / 150 deals)**, reserving the
`/product` deep-lookup (1 token each) for survivors' final validation.
→ Confirm the exact `deal.avg[MONTH] == stats.avg30` correspondence and measure
the token savings in **exp 05**.

## The two glitch sub-types (why the guard combination is needed)

- **Recent spike:** `avg30 ≈ current`, `avg90` inflated by a transient spike.
  Example `B0GHC3XM6J` (TV stand): current €19,99, avg30 €19,99, avg90 €1.699,96
  → caught by `spike-polluted-baseline`.
- **Persistent high baseline:** `avg30 ≈ avg90`, *both* inflated, so the spike
  ratio stays < 3 and the spike guard **misses**. Example `B0GJFS1HB5` (the exp02
  HDMI cable): current €6,99, avg30 €877,39, avg90 €918,35 → caught instead by
  `absurd-claim` (99% > 97) + `no-demand`. The HDMI cable's polluted average has
  grown from €543 (exp02) to €918 — the spike has *aged into* a stable baseline.

## The two survivors — and the recipe's real limitation

| ASIN | item | current | avg30 | avg90 | drop | rnkΔ90 | score |
|------|------|---------|-------|-------|------|--------|-------|
| `B0F6321QVZ` | DDR4 16GB RAM kit | €9,78 | €83,66 | €85,39 | 89% | 3 | 1.228 |
| `B0F2G9DLDP` | Corgi Rover P6 diecast | €43,51 | €504,31 | €501,02 | 91% | 2 | 1.003 |

Both pass *every* guard — but both are ~90% drops against a **stable high
baseline** (`avg30 ≈ avg90`, and `current` is the all-time min). The spike guard
is structurally blind to this: a baseline that has been wrong for >90 days (long
out-of-stock with high third-party gouging, then an Amazon restock at the real
price) looks identical to a genuine flash clearance. So these two are
**"needs live re-validation", not "publish".**

**Port recommendations:**
1. Drop `claim-divergence` (Finding 1).
2. Keep `spike-polluted-baseline` (the single strongest structural detector) +
   `abs-price-floor` + `no-demand` + `absurd-claim`. Keep the all-time-min floor
   and OOS guards as cheap insurance even though they barely fired here.
3. A persistently-wrong baseline defeats every avg-based check — the production
   funnel's **live price re-validation via the Amazon Creators API** is the real
   backstop for the survivors. Treat glitch-guard output as "candidates for live
   validation", never as final.
4. Run the guard as a /deal-page pre-filter; only deep-lookup survivors (exp 05).

## Decoding landmines re-confirmed
- **Deal arrays are 2D `[dateRange][priceType]`, NOT `[priceType][dateRange]`**
  (exp02). `deal.deltaPercent[90d][AMAZON]` = `[3][0]`; `current` is 1D. A
  first-draft of this probe had the indices swapped (silently reading SALES_RANK
  as a % drop) — flag loudly for the PHP port.
- `min`/`max` are all-time; `minInInterval`/`maxInInterval` are windowed (exp03).
- Deal sentinels `-2`/`0` (filtered by `> 0`); stats arrays `-1` (filtered by
  `statField`'s `>= 0`).

## Open / deferred
- Thresholds are first-pass, tuned to one amazon.de feed on 2026-05-30. The 3×
  spike ratio and 97% absurd-claim cap want a larger sample (exp 05).
- Precision/recall is qualitative — no ground-truth labels. "92% junk" is the
  reject rate on a worst-case (%-sorted) feed, not a measured false-positive rate.
  The two survivors show false-positives are still possible (stable-baseline
  glitches), which is exactly why live re-validation stays in the funnel.

## Files
- `run.ts` — the live probe (2 calls, 30 tokens, 11 ✓/✗ invariant checks).
- `analyze.ts` — offline re-analysis of the dumps (no API calls); recomputes the
  verdict table so the recipe can be re-tuned without re-spending tokens.
- `out/deal-page.dump.json`, `out/product-batch.dump.json` — raw (gitignored).

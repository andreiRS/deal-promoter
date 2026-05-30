# Experiment 05 - funnel dry run (deal -> pre-filter -> /product on survivors)

**Question:** End-to-end `/deal` -> glitch-guard filter -> batched `/product+stats`:
how many survivors per page, total tokens for the full pass, and throughput vs the
20/min refill - could this run sustainably?
**Chain:** `GET /deal` (sortType 4, dateRange 90d, 1 page) -> glitch-guard
**pre-filter on the deal payload alone** (exp04 Finding 2) -> `GET /product?stats=90`
on the deal-stage **survivors only**. **Run date:** 2026-05-30 - **Domain:** amazon.de (3)

## Result: PASS

11/11 funnel-invariant checks passed. The two-stage funnel runs as designed:
**150 deals -> 26 deal-stage survivors (deal payload only, 0 /product calls) -> 26
final survivors after the deep `/product` re-check.** A full pass cost **31 tokens**
(5 deal + 26 product). The deep stage rejected **0** of the 26 - every deal that
cleared the cheap pre-filter also cleared the trustworthy-stats re-check on this feed.

### Token cost - CONFIRMED
`/deal` page = **5** (1200->1195); `/product` batch of 26 survivors = **26**
(1195->1169). `stats=90` free. **Total 31 tokens** for a full deal->final-survivor
pass. `refillRate: 20/min`. README row 05's "~5/page + 1/survivor" holds exactly:
5 + 26 = 31. Free `/token` meter reads do not move the balance.

(Survivor count drifts slightly run-to-run as the live feed changes: an earlier run
saw 27 survivors / 32 tokens. The shape - `5/page + 1/survivor`, ~26 survivors of
150 on a raw sortType=4 page - is stable.)

## FINDING 1 - the pre-filter does ALL the rejecting for free (deal payload only); the deep stage added zero rejects here

The glitch-guard ran as a pure pre-filter on the `/deal` payload - **zero API calls** -
and cleared **124 of 150 deals** before any `/product` lookup. Pre-filter reject
histogram (a deal can trip several; deal-payload signals only):

| reason | hits | rule | source field |
|--------|------|------|--------------|
| `no-demand` | 81 | `salesRankDrops90 < 1` | deal `salesRankDrops90` |
| `spike-polluted-baseline` | 40 | `avg90 > 3 x avg30` | deal `avg[90]` / `avg[MONTH]` |
| `abs-price-floor` | 37 | `current < EUR 2,00` | deal `current[AMAZON]` |
| `absurd-claim` | 7 | `claimed% > 97` | deal `deltaPercent[90d]` |

All four are computed from the cheap 5-token deal page. The 26 survivors then went to
`/product?stats=90`, and the deep re-check (re-running the structural guards on
trustworthy stats + adding the OOS ceiling and all-time-min floor that need
`/product`) rejected **none of them** (0/26): every survivor had `oos90 <= 73%` (under
the 80% ceiling), `current >=` half its all-time min, and a stats-derived spike ratio
under 3x. So on this feed the **deep stage is pure confirmation, not extra filtering**
- the cheap deal-payload pre-filter already did the whole job. The OOS guard came
closest to firing (`B00OT75QTA` at 73%, `B00PRFIUPK` at 43%, `B0D2LQ9VY2` at 23%),
confirming it is the one structural check worth keeping at the deep stage even when it
does not reject (and the one signal genuinely absent from the deal payload).

## FINDING 2 - `deal.avg90` is NOT bit-exact to `stats.avg90` (~1 cent off, roughly half the time), but the derived spike ratio still agrees

exp04 reported `deal.avg[90]==stats.avg90` for 25/25. exp05's offline `analyze.ts`
re-check shows the equality is **only sometimes exact**: `deal.avg90` was bit-identical
to `stats.avg90` for **12-15 of 26** survivors (varies with the live feed) and off by
~1 cent for the rest - but **every one within 1 cent**. They are the same quantity
rounded slightly differently, **not the identical integer** exp04 implied (e.g.
`B0DNNH6C8X` deal EUR 121,03 vs stats EUR 121,02).

What matters for the funnel: the **derived spike ratio agrees between the two sources**
on every survivor (deal-ratio and stats-ratio land on the same side of the 3x threshold
for all 26), so the deal-payload pre-filter and the deep `/product` re-check reached the
same spike verdict, and **no survivor straddled the spike threshold** on this feed.

**Port consequence:** treat `deal.avg*` as the *same value rounded*, not bit-identical to
`stats.*` - compare with a tolerance, never `===`. Keep the spike check in both stages
anyway: the deal page is the cheap recall-safe net, `stats.avg30` is the canonical 30d
window for the deep re-check (the windows can diverge near a threshold even though they
did not here).

## FINDING 3 - the divergence check is dead, re-confirmed live (26/26)

For all 26 survivors **verified drop == claimed drop** (`(stats.avg90 - current)/
stats.avg90` rounds to the same percent as `deal.deltaPercent[90d][AMAZON]`; 26/26).
This reconfirms exp04 Finding 1: Keepa derives the deal's 90d % from the same 90d
average `/product` returns, so the claimed-vs-verified divergence check adds nothing
and is correctly **dropped** from the exp05 recipe.

## FUNNEL ECONOMICS - the headline metrics

| metric | value |
|--------|-------|
| deals pulled / page | 150 (150 with a live AMAZON price) |
| deal-stage survivors / page | **26** |
| final survivors / page | **26** (0 deep rejects) |
| deal tokens | 5 (5 x 1 page) |
| product tokens | 26 (1 x 26 survivors) |
| **total tokens / pass** | **31** |

### Throughput vs 20/min refill - SUSTAINABLE
A 31-token pass consumes **1.55 min** of refill at 20/min, so the funnel can run
**~1 pass every 1.6 min (~39 passes/hour)** on the entry tier without draining the
bucket. The 20/min tier's ~1,200 lookups/hour budget is not a constraint at this rate:
even at ~26 deep lookups per 150-deal page, a continuous loop costs ~31 tokens/pass.

### Pre-filter savings vs a naive top-25 deep lookup - NEGATIVE on this worst-case feed
exp04 deep-looked-up a fixed **top 25** (5 + 25 = 30 tokens). exp05's pre-filter left
**26 survivors**, so the full pass cost **31 tokens - 1 MORE than the top-25 lookup**.
This is the honest result, and it is feed-dependent: a raw `sortType=4` page is the
worst case for the pre-filter because, once the obvious junk (no-demand, spikes,
sub-EUR-2, absurd claims) is removed, the *remaining* deals are mostly plausible high-%
drops that all pass. **The pre-filter's value is correctness (it deep-looks-up only
deals that survived structural checks, not an arbitrary top-N), not raw token savings
on a glitch-sorted feed.** On a production query that is already filtered
(`isFilterEnabled`, sales-rank floors, category excludes), far fewer deals survive and
the `5/page + 1/survivor` model is cheap; the savings appear when the survivor count is
well below the deep-lookup batch size.

## The top final survivors (by ranking score)

| ASIN | item | current | stats.avg30 | stats.avg90 | drop | oos90 | rnkD90 | score |
|------|------|---------|-------------|-------------|------|-------|--------|-------|
| `B0010AH4BW` | Bosch mass-air-flow sensor | EUR 196,00 | EUR 853,01 | EUR 866,36 | 77% | 0% | 59 | 3.168 |
| `B01AYA0N0W` | 5five bamboo kitchen-roll holder | EUR 2,59 | EUR 13,50 | EUR 13,05 | 80% | 0% | 43 | 3.033 |
| `B0CWRYQWLG` | Filofax 2025 planner | EUR 3,61 | EUR 5,97 | EUR 15,36 | 76% | 0% | 23 | 2.431 |
| `B0F2G9DLDP` | Corgi Rover P6 diecast | EUR 43,51 | EUR 503,56 | EUR 500,77 | 91% | 0% | 2 | 1.003 |

The demand-led top of the ranking is the intended behaviour of the
`verified_drop x ln(1+salesRankDrops90) x (1 - oos/100)` score - the Bosch sensor and
the bamboo holder rank high on `salesRankDrops90`, not just on raw drop %.

`B0F2G9DLDP` (Corgi diecast) is again a ~90% drop off a **stable high baseline**
(`avg30 ~ avg90`, current is the all-time min) - exactly exp04's residual
false-positive class. It survives every guard at both stages, so it remains a
**"candidate for live re-validation", not "publish"**; the Amazon Creators API
live-price check is the real backstop.

## Worked example - how a product is filtered step to step

Illustrative numbers (rounded) showing how the funnel narrows a feed. Start from one
`/deal` page of 150 deals; 5 of them:

| Item | current | avg30 | avg90 | "drop" | sales? | verdict |
|------|---------|-------|-------|--------|--------|---------|
| RAM kit | EUR 10 | EUR 83 | EUR 85 | 88% | yes | **survives** |
| HDMI cable | EUR 7 | EUR 877 | EUR 918 | 99% | no | reject: `no-demand` + `absurd-claim` |
| TV stand | EUR 20 | EUR 20 | EUR 1.700 | 99% | yes | reject: `spike-polluted-baseline` (avg90 = 85x avg30) |
| EUR 1 case | EUR 1 | EUR 4 | EUR 4 | 75% | yes | reject: `abs-price-floor` (< EUR 2) |
| Toy | EUR 25 | EUR 60 | EUR 1.969 | 99% | no | reject: `no-demand` + `absurd-claim` |

**Stage 1 - pre-filter (free, runs on the /deal payload, 0 API calls):**
150 deals -> ~26 survivors. The four cheap rules (`no-demand`, `spike-polluted-baseline`,
`abs-price-floor`, `absurd-claim`) reject the obvious junk using only `current`,
`deltaPercent[90d]`, `avg[90]`/`avg[MONTH]`, `salesRankDrops90` - all already in the deal
object. In the example, only the RAM kit survives.

**Stage 2 - deep /product+stats (optional, 1 token/survivor):**
Re-check survivors on trustworthy stats; adds `outOfStockPercentage90` (OOS ceiling) and the
all-time-min floor, which need `/product`. On this feed it rejected 0/26. Optional - the
Creators API live check already re-validates before publish (see Finding 4 / rec. 2).

**Stage 3 - live re-validation (production, Creators API):**
Confirm the survivor's price is real *right now*. This is the mandatory backstop against the
stable-baseline glitch (e.g. `B0F2G9DLDP`) that defeats every average-based guard.

Flow: `150 deals -> (free filter) -> ~26 survivors -> (optional 26-token deep check) ->
candidates -> (Creators live re-check) -> publish`.

## Token usage by flow

Base facts: `/deal` page = **5 tokens flat** (any 150 deals); `/product` = **1 token per
survivor**; `stats=N` free; refill **20/min = 1,200/hour**. The entire variable cost is
**survivors per page** (~26 on a raw sortType=4 page; far fewer on a filtered production query).

**Per page (150 deals, ~26 survivors):**

| Flow | Tokens/page | /product cost |
|------|-------------|---------------|
| Deal-only (skip /product) | 5 | - |
| Deal + /product | 31 | +26 (1/survivor) |

The `/product` stage costs **~6.2x more** per page on this feed (the "loss" is exactly
1 token/survivor).

**Scaled (assuming ~26 survivors/page):**

| Volume | Deal-only | Deal + /product | Extra |
|--------|-----------|-----------------|-------|
| 1 page | 5 | 31 | +26 |
| 10 pages | 50 | 310 | +260 |
| 100 pages | 500 | 3,100 | +2,600 |

**Throughput vs 1,200/hr refill:**

| Flow | Tokens/pass | Max passes/hour |
|------|-------------|-----------------|
| Deal-only | 5 | ~240 |
| Deal + /product | 31 | ~39 |

Skipping `/product` makes the pipeline ~6x cheaper and ~6x more passes/hour. On a filtered
production query survivors drop well below ~26, shrinking the `/product` cost and turning the
pre-filter into a net saving (vs a naive top-N deep lookup).

## Port recommendations
1. **Run the glitch-guard in two stages.** Stage 1: pre-filter on the cheap 5-token
   `/deal` page using `current`, `deltaPercent[90d]`, `avg[90]`, `avg[MONTH]`,
   `salesRankDrops90` - this cleared 124/150 of a worst-case feed for free.
2. **The deep `/product` stage is OPTIONAL, not mandatory.** It rejected 0/26 here,
   and the Creators API live-price check (rec. 6) already re-validates every survivor
   before publish, so it is not required for correctness. Treat it as an optional
   pre-screen: its only unique signal is `outOfStockPercentage90` (absent from the
   deal payload; it came within 7 points of firing), useful to drop obvious
   OOS-poisoned baselines *before* spending a Creators API call. Skip it to save
   1 token/survivor; keep it only if Creators API calls are the scarcer budget than
   Keepa tokens. Default: skip, lean on the Creators backstop.
3. **Cost model: `5/page + 1/(deal-stage survivor)`.** Budget the scheduler to refill
   rate; ~31 tokens/pass is easily sustainable on 20/min (~39 passes/hour). The
   pre-filter saves tokens only when survivors fall well below the deep-lookup batch
   size, i.e. on a *filtered* production query, not a raw sortType=4 page.
4. **Compare `deal.avg*` to `stats.*` with a tolerance, never `===`** (Finding 2 -
   bit-exact only ~half the time, all within 1 cent; same value rounded differently).
5. **Drop the claim-divergence check entirely** (Finding 3 - verified drop == claimed
   drop for all 26 survivors; it adds zero information).
6. Treat final survivors as **live-validation candidates**, never publish-ready
   (the stable-baseline false-positive class, e.g. `B0F2G9DLDP`, survives every
   avg-based guard).

## Decoding landmines re-confirmed
- **Deal arrays are 2D `[dateRange][priceType]`** (exp02/04). `deal.avg[90d][AMAZON]`
  = `[3][0]`, `deal.avg[MONTH][AMAZON]` = `[2][0]`; `current` is 1D.
- Deal sentinels `-1`/`-2`/`0` (filtered by `>= 0` / `> 0`); stats arrays `-1`.
- `deal.avg[MONTH]` is a ~31-day window; `stats.avg30` is exactly 30 days - track
  closely but are not bit-identical (Finding 2).

## Open / deferred
- Single page, `sortType=4` (worst case for glitches). The 26/150 deal survivor rate
  and the *negative* pre-filter token saving are artefacts of the glitch-sorted feed;
  measure on a realistic multi-page **filtered** query before sizing the tier and
  claiming savings. (Set `PAGES=N` on `run.ts` to pull more pages.)
- The deep stage rejected 0 here, so its incremental value (OOS/floor) is unproven on
  this feed - it needs a feed that actually trips the OOS ceiling to demonstrate.
- Thresholds inherited from exp04 (first-pass, one feed). Precision/recall stays
  qualitative - no ground-truth labels; live re-validation remains the real backstop.

## Files
- `run.ts` - the live two-stage funnel (deal page + 1 survivor batch, 31 tokens,
  11 check invariant checks). Set `PAGES=N` to pull more deal pages.
- `analyze.ts` - offline re-analysis of the dumps (no API calls); recomputes both
  stages, the verified-vs-claimed drop, and the `deal.avg` vs `stats.avg` agreement so
  the recipe can be re-tuned without re-spending tokens.
- `out/deal-page-0.dump.json`, `out/product-batch-0.dump.json` - raw (gitignored).

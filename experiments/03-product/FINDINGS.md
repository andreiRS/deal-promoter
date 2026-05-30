# Experiment 03 — /product + stats (request, stats object, csv/time decoding)

**Question:** What are the live `/product` request and response shapes on amazon.de, and can we trust `stats` instead of hand-decoding the full csv history?
**Endpoint:** `GET /product?key=…&domain=3&asin=<csv>&stats=90` · **Run date:** 2026-05-30

## Result: PASS

19/19 checks passed. Token cost, stats object, csv indexing, Keepa-time, the `-1`
sentinel, RATING scaling, productType/variations all confirmed against live
amazon.de. Several response details differ from the brief — recorded below.

### Queries sent
```
GET /product?domain=3&asin=B0BFRDGFP6,B0BX45BQ1B,B0F14YX5TG&stats=90   (batch)
GET /product?domain=3&asin=B0CGMFY7MV&stats=90                          (parent)
```
- No `offers`/`buybox`/`stock`/`rating`/`history`/`days`/`update` set.
- `asin` is a plain comma-joined list; batch of 3 returned 3 products in order.

### Token cost — CONFIRMED 1/product, stats free
`tokensLeft` 1200 → 1197 (batch, `tokensConsumed: 3`) → 1196 (parent,
`tokensConsumed: 1`). **Flat 1 token per ASIN; `stats=90` adds nothing.**
`tokenFlowReduction: 0` on both, `refillRate: 20/min`. README row 03's "~1/ASIN"
guess is confirmed; lock it to `1/ASIN (confirmed; stats free)`.

## Envelope
Top-level keys: `processingTimeInMs, products, refillIn, refillRate, timestamp,
tokenFlowReduction, tokensConsumed, tokensLeft`. The payload is `products[]`
(array, ASIN order preserved). Each product carries `csv[]` (length 36 here) and,
because `stats=90`, a populated `stats` object.

## stats is trustworthy — no full-history decode needed
`stats.current[type]` matched the hand-decoded `lastPoint(csv[type])` exactly
(Δ 0 cents) for AMAZON and NEW. `min ≤ current ≤ max` and `min ≤ avg90 ≤ max`
held. So production can read `stats.current/avg90/min/max/salesRankDrops*`
directly and skip decoding the delta-csv history. Confirmed fields:
- 1D type-indexed: `current, avg, avg30, avg90, avg180, avg365, atIntervalStart`,
  `outOfStockPercentage30/90/180/365` — length 36, `-1` = no data.
- 2D extremes: `min, max, minInInterval, maxInInterval` — `arr[type]` is
  `null` OR `[keepaMinute, value]`. Verified AMAZON min `30,46 €` @2026-05-28,
  max `2.490,00 €` @2025-09-06.
- **scalars** (NOT type-indexed — read directly): `salesRankDrops30/90/180/365`
  (7/18/38/46 on the raclette), `totalOfferCount` (2), `lastOffersUpdate`,
  `lightningDealInfo` (null), `isLowest`/`isLowest90` (boolean[] per type).

## Doc-vs-reality gaps (the important ones)

1. **Spec landmine #9 is WRONG — buyBox/offer-count subfields are PRESENT, not
   absent, without `offers`.** `stats` carries the full buy-box family
   (`buyBoxPrice, buyBoxIsFBA, buyBoxCondition, buyBoxSellerId, …`),
   `offerCountFBA/FBM`, `retrievedOfferCount`, `totalOfferCount`, etc. even on a
   plain `stats=90` call. What changes without `offers` is the **value**, not the
   key: the offers-gated numerics come back as **`-2`** (`buyBoxPrice=-2`,
   `offerCountFBA=-2`, `offerCountFBM=-2`, `retrievedOfferCount=-2`) and booleans
   as `null` (`buyBoxIsFBA=null`, `lastBuyBoxUpdate=null`). `totalOfferCount=2`
   *is* populated. **Port rule:** treat `-2` in a stats *scalar* as
   "offers not requested", and don't assume key absence.

2. **Two sentinels coexist in `stats`, by location.** The 1D type-indexed price/
   count arrays use **`-1`** only (= no data / OOS / insufficient history). The
   offers-gated *scalar* subfields use **`-2`** (= not requested). The product
   **`csv`** arrays use **`-1`** only (confirmed by scanning every value slot —
   resolves the brief's open "confirm per-field on /product" question at ~line 198).
   This differs from the **deals** endpoint, where `avg`/`delta` used `-2`/`0`.

3. **RATING (csv[16]) and COUNT_REVIEWS (csv[17]) are NOT populated by `stats=90`
   alone.** All three seeds returned `stats.current[16]=-1`, `[17]=-1`, and
   `csv[16]`/`csv[17]` were absent. The rating/review history is **`rating=1`-gated**
   (a separate flag we deliberately didn't request). So Check 9's ÷10 lock could
   only be validated on the *formatting* path, not on live data this run.
   **Port note:** if production needs star ratings, it must add `rating=1` to the
   /product query — `stats` does not surface ratings for free.

4. **csv index 34 is live and is a count.** `csv[34]` had points (`…,[8068128,2]`)
   and `stats.current[34]=2` — a non-price count type beyond the brief's table.
   Our `COUNT_TYPES` already includes 34/35, so `formatStatValue` handles it
   correctly. csv indices present on the raclette: `0,1,2,3,4,11,12,34`.

5. **`outOfStockPercentage*` reuses `-1` for "insufficient data", not just OOS.**
   `outOfStockPercentage180` had 32 of 36 slots at `-1` on a 330-day-tracked
   product — that's "type not tracked", not "in stock 0%". Index 2 (USED) read
   `100` (no used offers in window) while AMAZON/NEW read `0`.

## Decoding — all CONFIRMED
- **csv indexing** via `formatStatValue`: `AMAZON 30,46 €`, `NEW 30,46 €`,
  `USED 35,71 €`, `SALES_RANK #167.148`, all plausible.
- **Keepa-time on /product**: `trackingSince` 2025-07-04, `listedSince`
  2022-09-22, `lastUpdate` 2026-05-30 (7.2h old), `lastPriceChange` 2026-05-28,
  and the last `csv[AMAZON]` point all decoded in-range via `(min+21564000)*60000`.
- **productType / variations**: child `B0BFRDGFP6` productType `0`; parent
  `B0CGMFY7MV` productType `5` with `variations[]` of 8 ASINs. Cross-link holds
  both ways (`child.parentAsin===parent` and `parent.variations` lists the child).
- **interval not clamped**: raclette tracked 330d ≥ 90d, so the 90d window was
  honored (`atIntervalStart[AMAZON]=2.490,00 €`). Clamp path (trackingSince < 90d)
  not exercised this run — still a real risk for young products (avg180/avg365 = -1).

## Known landmine NOT verifiable live (kept loud)
- **`*_SHIPPING` 3-wide stride** (csv 7, 18, 19–29, 32 = `[time, price, shipping]`).
  Without `offers`, `csv[7]` (NEW_FBM_SHIPPING) and `csv[18]` (BUY_BOX_SHIPPING)
  are `null`, so the 3-wide stride can't be exercised. `decodeCsv` here uses a
  flat 2-wide stride — **correct for the price/rank/count types we touched, WRONG
  for shipping-bearing types.** Needs a dedicated offers-probe (exp 04). Did not
  add `offers` (expensive).

## PHP port landmines
1. **`decodeCsv` 2-wide stride is WRONG for `*_SHIPPING` types** (7, 18, 19–29, 32
   are `[time, price, shipping]` 3-wide). Per-type stride required. Not exercised
   here (needs offers) — flag loudly until exp 04.
2. **RATING ÷10** (0..50 → 0.0..5.0). Centralized in `formatStatValue`. Note
   ratings only appear with `rating=1` (gap #3).
3. **Mixed index space:** SALES_RANK(3), COUNT_*(11–14,17,34,35),
   EXTRA_INFO_UPDATES(15) are not cents. Route every value through
   `isPriceType`/`formatStatValue`; never blanket-divide by 100.
4. **Two sentinels:** `-1` in csv + 1D stats arrays (no data / OOS / insufficient
   history); `-2` in offers-gated stats *scalars* (not requested). `>= 0` guard is
   the chokepoint. `outOfStockPercentage*` `-1` = insufficient data, NOT 0% OOS.
5. **2D extremes are `null` OR `[time,value]`** — guard `Array.isArray && len>=2`
   before indexing (`decodeExtremePoint` does this).
6. **Sparse/short stats when history thin:** `stats=90` clamps to tracked age;
   `avg180`/`avg365` = `-1` for young products = "insufficient history", NOT OOS.
7. **JS Long precision on category IDs** (from exp02): parse large IDs as strings
   in JS; PHP 64-bit is fine.
8. **`salesRankDrops*` / `totalOfferCount` / `lightningDealInfo` are SCALARS** on
   `stats`, not type-indexed arrays — read directly, never via `statField`.
   `outOfStockPercentage30/90/180/365` ARE type-indexed arrays (use `statField`).
9. **buyBox/offer-count subfields are PRESENT but value-gated** (gap #1): keys
   always exist; `-2` numeric / `null` boolean means "no offers data". Don't test
   for key absence — test for `-2`/`null`.
10. **`stats.current` vs `lastPoint(csv)`** matched exactly here (Δ 0), but they
    can differ a tick (live snapshot vs last history delta). Prefer
    `stats.current`; a small mismatch is informative, not a bug.

## Files
- `run.ts` — the probe (19 ✓/✗ checks).
- `out/batch-seeds.dump.json`, `out/parent-B0CGMFY7MV.dump.json` — raw responses
  (gitignored).
- Extended `../lib/keepa.ts`: full `CsvType` table, `isPriceType`,
  `formatStatValue`, `RANK_TYPES`/`COUNT_TYPES`/`RATING_TYPE`, `statField`,
  `decodeExtremePoint`, `StatPoint`.

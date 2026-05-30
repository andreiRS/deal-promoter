# Experiment 02 — Browsing Deals (request + Deal Object)

**Question:** What are the live request and response shapes for `/deal` on amazon.de?
**Endpoint:** `GET /deal?key=…&selection=<urlEncodedJSON>` · **Run date:** 2026-05-30

## Result: PASS

One page returned **150 deals** for amazon.de. Token cost, envelope, image/time/price
decoding all confirmed. Several response fields are present that the docs don't list.

### Query sent
```json
{ "page": 0, "domainId": 3, "priceTypes": [0], "dateRange": 1,
  "isRangeEnabled": true, "deltaPercentRange": [20,100],
  "sortType": 4, "filterErotic": true, "isFilterEnabled": false }
```
- `selection` is passed as a **URL-encoded JSON string** on GET — works fine via
  `URLSearchParams`. POST is the alternative for big queries.
- `domainId` and exactly-one `priceTypes` are required, as documented.

### Token cost — CONFIRMED 5, not "~1/page"
`tokensLeft` 1200 → 1195, `tokensConsumed: 5` for a 150-deal page. The README
table's "cheap (~1/page)" guess was wrong; **it's a flat 5 per request/page**
regardless of how many deals come back. Updated the README.

## Envelope
Top-level keys: `deals, dealsCached, processingTimeInMs, refillIn, refillRate,
timestamp, tokenFlowReduction, tokensConsumed, tokensLeft`.

`deals` keys: `dr, categoryIds, categoryNames, categoryCount, drDateIndex`.
- `dr.length === 150` → page is full; you'd page on until a page returns < 150.
- `categoryNames`/`Ids`/`Count` are index-aligned root-category rollups (59 roots
  this run; e.g. "Musik-CDs & Vinyl" 1505, "Kosmetik" 638).

## Surprises vs. the pasted docs
1. **Undocumented top-level field `dealsCached`** (Boolean, `false` here). Likely
   "served from deal cache" — watch whether it ever pairs with a 0-token cost.
2. **Undocumented envelope field `drDateIndex`** — present but an **empty array**
   in this run. The documented per-deal `dateIntervalIndex` exists instead (see #3).
3. **Deal Object has extra fields not in the docs:**
   - `dateIntervalIndex` — per-deal (the documented envelope `drDateIndex` was empty).
   - `backInStock` — Boolean on the deal (docs only listed `isBackInStock` as a query filter).
   - `lightningStart` — paired with the documented `lightningEnd`.
   - `salesRankDrops30 / 90 / 180 / 365` — **sales-rank drop counts** over those
     day windows. Not documented, genuinely useful as a demand signal for ranking deals.
4. **Documented fields that were ABSENT on the sampled deal:**
   - `parentAsin` — omitted when there's no parent (present only on variation children).
   - `warehouseConditionComment` — absent (only `warehouseCondition` present; comment
     presumably appears only for actual warehouse deals).

## Decoding — all CONFIRMED
- **`image`**: US-ASCII char-code array → filename, e.g. `111tNa5a-tL.jpg`. CDN URL
  `https://images-na.ssl-images-amazon.com/images/I/<name>`.
- **Keepa-time**: `lastUpdate`/`currentSince`/`creationDate` decode correctly via
  `(min + 21564000) * 60000`.
- **`current` is Price-Type indexed** (same index space as product `csv`). Verified
  on deal B0GJFS1HB5: `current[0]`(AMAZON)=699, `[1]`(NEW)=699, `[2]`(USED)=685,
  `[16]`(RATING)=45→4.5★, `[17]`(COUNT_REVIEWS)=18, `[18]`(BUY_BOX)=699.
- **`delta`/`deltaPercent`/`avg` are 2D `[dateRange][priceType]`**. Verified.

### Sentinel values differ between arrays (important)
- `current`: **`-1`** = no offer / out of stock.
- `avg` (and the no-data slots in `delta`): **`-2`** = no data. `delta` also uses
  **`0`** for "no change / not calculable".
- Decode rule that works for all: treat any value `< 0` as absent; for `delta`,
  `0` is also "ignore".

## Data-quality landmine: sortType=4 surfaces glitch deals
Sorting by **percentage delta (4)** put obviously bogus deals on top: a €6.99 HDMI
cable with a **weighted avg of €543.66** (99% "drop"), a €25.90 toy vs €1,969 avg,
a €179 walking pad vs €5,561 avg. The numbers are internally consistent
(`avg − current = delta`), so it's not a decode bug — Keepa's weighted average is
polluted by transient price-glitch spikes (e.g. a brief €999 listing). **For the
production deal promoter, ranking purely on `deltaPercent` will float garbage to the
top.** Mitigations to test later: sanity-bound `currentRange`/`avg`, prefer
`ABSOLUTE_DELTA` (2) or combine with `salesRankDrops*`, or cap deltaPercent upper bound.

## JS Long-precision landmine (port note)
The "unknown root category" sentinel `9223372036854775807` (Long.MAX_VALUE) is read
back by JS `JSON.parse` as `9223372036854776000` — it exceeds `Number.MAX_SAFE_INTEGER`.
Real node IDs seen (562066, 12950651, 1382696031) are all < 2^53 and safe. **PHP's
64-bit ints handle this natively; any JS-side processing must parse large category IDs
as strings.** Treat the sentinel (or `rootCat` 0) as "unknown root category".

## Port notes (PHP client)
- Flat 5 tokens/page; page until a page yields < 150 (cap 10,000 ASINs).
- Model the Deal Object with the extra fields above (esp. `salesRankDrops*`).
- Centralize the `<0` / `delta==0` sentinel handling in the decoder.
- Don't trust `deltaPercent` alone for ranking — add glitch guards.

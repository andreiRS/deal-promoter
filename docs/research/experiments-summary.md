# Experiments summary — critical questions & answers

A cross-cutting digest of the `experiments/` research suite (TS/bun throwaway probes;
learnings port to the PHP/Symfony production client). Each entry is the question an
experiment set out to answer and what we actually proved against live amazon.de.
Per-experiment detail lives in each `experiments/NN-*/FINDINGS.md`; backing briefs are
[`keepa.md`](keepa.md) and [`creators-api.md`](creators-api.md).

The pipeline (see [`../specs/product.md`](../specs/product.md)): **Keepa** does cheap,
batchable deal *discovery* + glitch pre-filtering; the **Creators API** does live price
*validation* and supplies the affiliate link. The non-negotiable rule both halves point
at: **never publish a price you have not just re-confirmed live on Creators.**

Full flow: `deal → free glitch pre-filter → optional deep stats check → Creators live
re-validation → publish`.

---

## Keepa API — discovery + glitch-filtering (exp 01–05)

### 01 · Does the key work, and what's our token budget?
Yes. `/token` is **free** (0 tokens) and the meter rides on the JSON body, not headers.
We're on the **20 tokens/min plan, cap ~1200** (≈1200 ASIN deep-looks now, sustained
~20/min). Undocumented meter fields: `tokenFlowReduction` (throttle penalty) and
`processingTimeInMs`.

### 02 · What do `/deal` request + response look like on amazon.de?
One page = **150 deals**, flat **5 tokens/page** regardless of count (the "~1/page" guess
was wrong). Confirmed decoding: `image` (char-code array → CDN filename), Keepa-time
`(min+21564000)*60000`, and deal arrays are **2D `[dateRange][priceType]`**. Useful
undocumented fields: `salesRankDrops30/90/180/365` (demand signal), `lightningStart`,
`backInStock`. Sentinels differ by array: `current` = `-1`; `avg`/`delta` = `-2`/`0`.
- **Landmine:** sorting by `deltaPercent` (sortType 4) floats **price-glitch garbage** to
  the top (e.g. a €6.99 cable with a €543 "avg"). Ranking on drop-% alone publishes junk.

### 03 · Can we trust `stats` instead of decoding full csv history?
Yes. `stats=90` is **free** and `stats.current` matched hand-decoded csv exactly. Read
`current/avg30/avg90/min/max/salesRankDrops*` directly. **1 token/product**, batchable.
Corrections to the brief:
- Buy-box/offer-count keys are **always present but value-gated** — `-2` means "offers not
  requested", *not* key-absent. Don't test for missing keys; test for `-2`/`null`.
- `*_SHIPPING` csv types are **3-wide stride** `[time, price, shipping]`.
- Ratings need a separate `rating=1` flag — `stats` does not surface them for free.

### 04 · Which `stats` bounds separate real deals from glitches?
The "claimed vs verified drop" idea is **dead** — Keepa derives both from the same avg90,
so divergence is always 0 (drop it). What works structurally: `spike-polluted-baseline`
(avg90 > 3×avg30), `no-demand` (salesRankDrops90 < 1), `abs-price-floor` (< €2),
`absurd-claim` (>97%). Of the top-25 by %-drop, **only 2 survived**. Crucially, **all four
guards run on the cheap `/deal` payload alone** — no `/product` needed to pre-filter.
- **Residual risk:** a *persistently-wrong baseline* (long OOS + third-party gouging, then
  an Amazon restock at the real price) defeats every average-based guard. Live
  re-validation is the only real backstop.

### 05 · Does the end-to-end funnel run sustainably?
Yes. **150 deals → ~26 survivors (free pre-filter, 0 API calls) → 26 after deep re-check.**
Full pass = **31 tokens** (5 deal + 26 product) ≈ 1.55 min of refill → **~39 passes/hour**,
sustainable on the entry tier. The deep `/product` stage rejected **0/26** here — it's
**optional confirmation**, its only unique signal being `outOfStockPercentage90`. Treat
final survivors as **"candidates for live validation," never publish-ready.** Compare
`deal.avg*` to `stats.*` with a **tolerance, never `===`** (same value, rounded; within 1
cent, not bit-identical). Pre-filter token savings are feed-dependent — they appear on a
*filtered* production query, not a raw worst-case sortType=4 page.

---

## Amazon Creators API — live validation + affiliate link (exp 06–08)

### 06 · Which auth path, and does the token exchange work?
Our credential is **Version 3.2 → Login with Amazon**. Token endpoint
`api.amazon.co.uk/auth/o2/token`, scope `creatorsapi::default`, JSON body,
`Authorization: Bearer <token>` with **no Version suffix**, `expires_in` 3600s, in-memory
cache reuse works. Gotchas: Version is written `v3.2` (strip non-digits before parsing the
major); `token_type` returns lowercase `bearer` (build the header yourself, don't echo it).

### 07 · What does GetItems return, and does the link carry our tag?
**PASS.** `POST https://creatorsapi.amazon/catalog/v1/getItems` (dotless `.amazon` gTLD),
marketplace via the `x-marketplace` header, lowerCamelCase body + envelope.
**`detailPageURL` already carries `tag=` + `linkCode=ogi`** — use it verbatim, no
link-building. 1 call = 1 transaction.
- **Surprise:** per-item `errors[]` entries are `{code, message}` only — **no `asin` field**
  (a bad ASIN gives `InvalidParameterValue`, *not* `ItemNotAccessible`, with the ASIN only
  in the message string). Map failures by diffing requested ASINs against `items[].asin`.

### 08 · Does the API tell us a product is on a real discount?
**It tells us Amazon *flags* a deal, not whether it's *genuine*.** A live deal returned
`price.savingBasis` (was-price), `price.savings` (`{money, percentage}`), and `dealDetails`
(badge + window); a no-deal item had none of the three. But the was-price is
`savingBasisType: LIST_PRICE` (the gameable MSRP) → **the real-drop gate stays `price.money`
vs the Keepa baseline**; savings%/dealDetails are corroboration and post copy, not the gate.
Corrections to the brief:
- Envelope is **lowerCamelCase** end to end (the brief's PascalCase was SDK-derived/wrong).
- **Multiple listings of different conditions return, and the cheapest is NOT the buy box** —
  select `isBuyBoxWinner === true`, never `listings[0]` or min-price.
- "OffersV2 only returns NEW" is **refuted** (a Used listing came back). Gate on
  `buyBox.condition.value === "New"`.
- Captured the **.de Amazon seller id `A3JWKAKR8XB7XF`** for the sold-by-Amazon gate (there
  is no FBA boolean). `violatesMAP` exists and is checkable.
- **No rate-limit headers** observed on the GetItems response.

---

## The through-line
- **Keepa is cheap, batchable discovery** (5/page + 1/survivor, ~39 passes/hr) — but its
  averages are **glitch-polluted**, and no average-based guard can catch a
  persistently-wrong baseline.
- **Creators is the live backstop** — authoritative current price + Amazon's own deal claim
  + the ready-to-post tagged affiliate URL.
- **Never publish a price you have not just re-confirmed live on Creators.** That single
  rule is why the funnel ends in a Creators re-validation step before publish.

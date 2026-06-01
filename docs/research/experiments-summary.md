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

## Full pipeline — discovery + validation wired together (exp 09)

### 09 · Does the whole pipeline run in one pass, and does it publish *true* deals?
**Yes to the wiring, no to the second half as specced.** One automated pass (`discover` →
`validate` → `renderHtml`, **two API calls total**: 5 Keepa tokens + 1 Creators transaction)
took 150 deals → 17 free-pre-filter survivors → top-10 by score → live Creators gate → a branded
HTML table. The handoff works and the affiliate `detailPageURL` (with `tag=`) renders.
- **Load-bearing finding:** live validation confirms a price is *real and buyable*, NOT the
  *magnitude* of the discount. The gate's "live price vs Keepa `avg90`" published fake 80%+ drops:
  one item read 80% off avg90 but Amazon's own `WAS_PRICE` said 12%; another read 84% off while
  Amazon flagged no deal at all. Keepa's avg90 is the persistently-polluted baseline (long OOS +
  third-party gouging) that exp04/05 warned about, and the live call cannot un-pollute history.
- **`savingBasisType: WAS_PRICE` exists** (refines exp08's "always `LIST_PRICE`"): Amazon's recent
  actual selling price, the one trustworthy magnitude baseline. But of 10 validated items only
  **4** carried `savings`, and **3 of those 4 were `LIST_PRICE`** (gameable MSRP, claiming 81–88%);
  only **1** had `dealDetails` + `WAS_PRICE`. So "require Amazon `savings`" is NOT a fix; only
  `dealDetails`/`WAS_PRICE` are trustworthy, and they were rare (1 of 10) on this page.
- **The fix (≈free, no extra call):** trust Amazon `dealDetails` + `savingBasisType == WAS_PRICE`
  for the advertised %, treat Keepa as discovery/ranking only, advertise the conservative
  `min(keepa, amazon)`, and require a stable Keepa baseline (`outOfStockPercentage90` low,
  avg30≈avg90≈avg180) when Amazon attests nothing. The durable fix is the spec's own `record`
  step: once we log live prices each cycle, our own history is the un-gameable baseline.
- **No deep `/product?stats` stage:** dropped on purpose. It would not catch the above (live
  validation can't un-pollute history; the magnitude fix lives in the Creators fields), and it
  cost ~1 token/survivor for a 0/26 rejection in exp05.
- `availability.type` seen live: `IN_STOCK`, `IN_STOCK_SCARCE` (buyable), `LEADTIME` (ships in
  2 to 3 days, treated as not-in-stock; correctly produced the run's single SKIP).
  `price.money.amount` is a **decimal euro** number; convert to integer cents at the boundary,
  never compare floats or cross the Keepa-cents / Creators-euros boundary unconverted.

---

## The through-line
- **Keepa is cheap, batchable discovery** (5/page + 1/survivor, ~39 passes/hr) — but its
  averages are **glitch-polluted**, and no average-based guard can catch a
  persistently-wrong baseline.
- **Creators is the live backstop** — authoritative current price + Amazon's own deal claim
  + the ready-to-post tagged affiliate URL.
- **Never publish a price you have not just re-confirmed live on Creators.** That single
  rule is why the funnel ends in a Creators re-validation step before publish.
- **Live validation proves price *validity*, not discount *magnitude* (exp09).** The "% off"
  always rests on a baseline, and every baseline we have is gameable or pollutable: Keepa avg90
  is glitch-polluted, Amazon `LIST_PRICE` is gameable MSRP. Only Amazon `dealDetails` +
  `WAS_PRICE` is trustworthy, and it is rare. Advertise the conservative cross-source minimum;
  the durable baseline is our own recorded price history once the `record` step exists.

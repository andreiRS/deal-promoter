# Keepa API Research (for Deal Promoter)

High-level research brief on Keepa and its API, oriented toward the Deal Promoter pipeline
(find amazon.de price-drop deals via the deals endpoint, deep-lookup survivors via /product,
validate against Amazon Creators API, publish affiliate links to WhatsApp).

> Note on numbers: the official API page (keepa.com/#!api) is a hash-route SPA that does not
> scrape, so several figures below come from the official Java backend repo, the community
> Python wrapper docs, and third-party guides. Anything marked CONFIRM should be verified on
> the live API page once we have an account, because token costs and prices change.
>
> **Live-verified (2026-05-30):** items marked **[VERIFIED]** were confirmed against the live
> amazon.de API by the throwaway probes in [`../../experiments/`](../../experiments/). See
> [`experiments/01-key-and-tokens/FINDINGS.md`](../../experiments/01-key-and-tokens/FINDINGS.md)
> (token meter) and [`experiments/02-deals/FINDINGS.md`](../../experiments/02-deals/FINDINGS.md)
> (deals request + Deal Object). Where the live API disagreed with the docs, the brief now
> reflects the live behaviour and links the finding.

## 1. What Keepa is

- Keepa is the de facto standard Amazon price-history and price-tracking service. It is best
  known for the price-history graphs (Amazon / New / Used / Buy Box over time) that you see
  embedded all over the Amazon reselling and deal-hunting community.
- It runs a popular browser extension (overlays a price chart on Amazon product pages) plus a
  website with price-drop alerts, availability alerts, and cross-marketplace price comparison.
- It tracks essentially the entire Amazon catalog across all major marketplaces and stores
  long-running history (years) for price, used price, Buy Box, sales rank, offer counts, etc.
- Audience: resellers / FBA sellers, retail-arbitrage and online-arbitrage operators, deal
  sites, repricers, and data/analytics shops. The same dataset is exposed programmatically
  through the Keepa API.
- For us the relevant value is twofold: (a) the deals feed surfaces current price drops
  cheaply, and (b) the per-ASIN history lets us judge whether a "deal" is actually a deal
  versus a fake markdown.

## 2. API access, tokens, and pricing

- The API lives at https://keepa.com/#!api (account, key, live token meter, and docs in the
  same SPA). Forum-style docs live under https://keepa.com/#!discuss (e.g. the "Browsing
  Deals" thread).
- Access is a paid monthly subscription, separate from the website/extension data
  subscription. You get an access key that you pass on every request.
- Usage is metered with a token-bucket system, not a simple request count:
  - You have a token balance that refills at a fixed rate per minute. The refill rate is
    your subscription tier (e.g. a 20-tokens/min plan refills 20 per minute). **Tokens
    expire 60 minutes after they are generated**, so an unused balance does not accumulate
    indefinitely; you cannot "save up" for a big burst beyond one hour of refill.
    **[VERIFIED]** our test key is the 20/min tier: live `/token` reported
    `refillRate: 20`, `tokensLeft: 1200`, so the **balance cap is ~1200** (refillRate × 60),
    consistent with the 60-minute expiry.
  - The official rule of thumb: **1 token retrieves the complete data set for 1 product.**
    So a plain /product lookup is about 1 token per ASIN.
  - Asking for `offers` / extra data on /product is much more expensive (each offers page
    adds several tokens). Avoid `offers` unless we truly need live marketplace offers.
  - **[VERIFIED]** A deals call costs a **flat 5 tokens per page** (not "~1/page" — an earlier
    draft underestimated this), and returns **up to 150 deals per page** regardless, so it is
    still very cheap per-deal (~0.03 tokens/deal on a full page). This is exactly why our
    "deals first, /product only for survivors" design is the right shape. A query can page up
    to 10,000 ASINs. See `experiments/02-deals/FINDINGS.md`.
  - The API response carries the live balance and refill info (tokensLeft, refillIn,
    refillRate); the Python wrapper surfaces these (`tokens_left`, `time_to_refill`) and the
    Java framework exposes the same. Read them on every call to self-throttle. **[VERIFIED]**
    Checking the key/balance via `GET /token` is **free** (`tokensConsumed: 0`). The meter
    body also carries two fields not in the docs: **`tokenFlowReduction`** (0 when healthy —
    presumed throttle/penalty indicator that rises under over-requesting; watch it) and
    `processingTimeInMs`. See `experiments/01-key-and-tokens/FINDINGS.md`.
- Pricing tiers (priced by token throughput; figures corroborated across third-party guides,
  CONFIRM on the live page since they drift):
  - 20 tokens/min  ~ €49/month  (entry API tier)
  - 60 tokens/min  ~ €129/month
  - 250 tokens/min ~ €459/month
  - 1000 tokens/min ~ €1,499/month
  - 4000 tokens/min ~ €4,499/month
  Note: the cheap €19/month consumer Keepa subscription is the website/extension plan and is
  not the API; it grants only token ~1/min, effectively unusable for batch work. Treat token
  throughput, not price, as the real constraint. A 20/min plan is only ~1,200 product
  lookups/hour, so our funnel must lean on the cheap deals endpoint and keep /product calls
  to genuine survivors.

## 3. Parts of the API essential for us

### Deals / browsing-deals endpoint (our primary funnel)
- **[VERIFIED]** `GET /deal?key=…&selection=<urlEncodedJSON>` (or POST with the JSON as the
  body for large queries). Returns up to **150 deals per page** for a given marketplace,
  filtered by the `selection` deal-query object. Paginate with `page` (start 0, stop when a
  page returns < 150; cap 10,000 ASINs). **Flat 5 tokens/page.** `domainId` and exactly one
  entry in `priceTypes` are **required** (multiple price types per query are not supported).
- **Only products updated in the last 12 hours appear in deals.** `dateRange` selects the
  change window: 0 = day (24h), 1 = week (7d), 2 = month (31d), 3 = 90 days.
- Filter parameters (the live `selection` object has ~70 keys; the ones we care about):
  - `domainId` (marketplace, see below; **3 = Germany/amazon.de** for us)
  - `priceTypes` (single-element array: 0=Amazon, 1=New, 2=Used, 10=New_FBA, 18=Buy Box,
    8=Lightning, 9=Warehouse, 33=Prime-exclusive, … — same index space as `csv`)
  - `deltaRange`, `deltaPercentRange`, `deltaLastRange` (size of the drop, absolute /
    percent / vs last value) -> this is how we set "only big drops". `deltaPercentRange`
    minimum is 10% (80% for Sales Rank).
  - `currentRange` (current price window, in cents), `salesRankRange` (popularity window;
    **using it excludes products with no sales rank**; `-1` as upper bound = open)
  - `includeCategories`, `excludeCategories` (up to 500 node IDs)
  - `minRating` (0..50, -1 = off), `hasReviews`
  - `isLowest`, `isLowest90`, `isLowestOffer`, `isHighest`, `isOutOfStock`, `isBackInStock`,
    `isRisers`, `isPrimeExclusive`, `mustHaveAmazonOffer`, `singleVariation`
  - `isRangeEnabled` / `isFilterEnabled` (master switches for the range / filter blocks)
  - `titleSearch` (space-separated keywords, all must match), `filterErotic`
  - `sortType` (1=deal age newest, 2=absolute delta, 3=sales rank, 4=percent delta; negate
    to invert except 1), `dateRange` (change window, above)
- Practical use: set domainId=3, a price type, a deltaPercentRange threshold, sales-rank and
  rating floors, and exclude junk categories, then page through. Survivors get a /product
  deep lookup.

#### Response shape **[VERIFIED]**
- Envelope: `{ deals: { dr, categoryIds, categoryNames, categoryCount, drDateIndex } }` plus
  the usual token-meter fields and a top-level **`dealsCached`** boolean (both undocumented).
  `dr` is the deal array; `categoryIds/Names/Count` are index-aligned root-category rollups.
- Each **Deal Object** carries `current` (Price-Type-indexed, like `csv`), and the 2D arrays
  `delta` / `deltaPercent` / `avg` indexed **`[dateRange][priceType]`** (the day index 0 of
  `avg` is actually a 48h average). Plus `asin`, `parentAsin` (only on variation children),
  `title`, `rootCat`, `categories`, `image`, `currentSince`, `creationDate`, `lastUpdate`,
  `lightningEnd`, `warehouseCondition`.
- **Fields present live but not in the docs:** `salesRankDrops30/90/180/365` (sales-rank drop
  counts per window — a useful demand signal for ranking deals), `dateIntervalIndex`,
  `backInStock`, `lightningStart`. Model these in the PHP Deal struct.
- **`image`** is an array of US-ASCII char codes for the filename only
  (e.g. → `61k3Lay7JUL.jpg`); full URL `https://images-na.ssl-images-amazon.com/images/I/<name>`.
- **Sentinels differ between arrays** (centralize in the decoder): `current` uses **`-1`** for
  "no offer / OOS"; `avg` and empty slots of `delta` use **`-2`** for "no data"; `delta` uses
  **`0`** for "no change / not calculable". Rule that works everywhere: treat any value `< 0`
  as absent, and for `delta` also ignore `0`.

#### Two landmines for the production funnel **[VERIFIED]**
- **`sortType=4` (percent delta) floats data-glitch deals to the top.** A €6.99 cable showed a
  €543 "weighted average" → 99% "drop"; the math is internally consistent (`avg − current =
  delta`), so Keepa's average is polluted by transient price spikes, not a decode bug. Ranking
  on `deltaPercent` alone surfaces junk — add glitch guards (sanity-bound `currentRange`/`avg`,
  prefer `sortType=2` absolute delta, and/or combine with `salesRankDrops*`).
- **JS Long-precision:** the "unknown root category" sentinel `9223372036854775807`
  (Long.MAX_VALUE) corrupts under JS `JSON.parse` (→ `…776000`); PHP 64-bit ints are fine.
  Real node IDs seen are all < 2^53 and safe. Treat that sentinel (or `rootCat` 0) as
  "unknown root category". Relevant only if any tooling round-trips deals through JS.

### /product endpoint (per-ASIN deep history)
- Query one or many ASINs; returns the full product object with delta-encoded history arrays.
- Useful options: `stats` (precomputed min/max/avg over N days, ideal for "is this actually
  the lowest in 90/180 days?"), `history` (toggle full history), `rating`, and `offers`
  (expensive, live offers/Buy-Box, only if needed). Use `stats` to validate deals without
  decoding the whole CSV ourselves.
- **[VERIFIED]** (exp 03, [`../../experiments/03-product/FINDINGS.md`](../../experiments/03-product/FINDINGS.md)):
  **token cost is a flat 1 per ASIN** (batch of 3 cost `tokensConsumed: 3`), batch ≤ 100 ASINs
  per call, and **`stats=N` adds no token cost** (`tokenFlowReduction: 0`). The response payload
  is `products[]` in ASIN order.
- **[VERIFIED]** `stats=90` returns, per Price-Type index, `current` / `avg` /
  `avg30/90/180/365` / `atIntervalStart` / `outOfStockPercentage30/90/180/365` (1D arrays,
  `-1` = no data), and `min` / `max` / `minInInterval` / `maxInInterval` as **2D extremes**
  (`null` OR `[keepaMinute, value]`). `salesRankDrops30/90/180/365`, `totalOfferCount` and
  `lightningDealInfo` are **scalars** on `stats`, not type-indexed. `stats.current` matched the
  hand-decoded last `csv` point exactly, so production can read `stats` and skip full-history
  decoding for the "is this the lowest in 90d?" check.
- **[VERIFIED] sentinel split inside `stats`:** the 1D type-indexed arrays use `-1` (no data /
  OOS / insufficient history); the **offers-gated *scalar* subfields use `-2`** = "offers not
  requested" (`buyBoxPrice=-2`, `offerCountFBA/FBM=-2`, `retrievedOfferCount=-2`,
  `buyBoxIsFBA=null`). Those buy-box/offer-count keys are **present even without `offers`** —
  only their values are gated, so test for `-2`/`null`, not key absence.
- **[VERIFIED] `rating`/reviews are not free:** `RATING` (csv 16) and `COUNT_REVIEWS` (csv 17)
  came back `-1` / absent on a plain `stats=90` call. Star ratings require adding `rating=1` to
  the query; `stats` alone does not surface them.
- Interval-shortening: `stats=N` clamps to the product's tracked age. `avg180`/`avg365` = `-1`
  on a young product means "insufficient history", NOT out-of-stock.

### Product Finder
- A search/filter over the whole catalog (criteria dict, returns ASIN list). More of a
  discovery/seed tool than our hot path; the deals endpoint is the better primary funnel for
  price-drop hunting.

### Best Sellers endpoint (category -> top ASINs)
- `GET /bestsellers?key=&domain=&category=&range=` returns an ASIN list of the most popular
  products by sales in a category (or a website display product-group name).
- **Token cost: 50 per call** (much pricier than deals; this is not a hot-path tool). An empty
  result (no list for that category/locale) consumes **0 tokens**.
- List sizes: root categories up to 500,000 ASINs; sub-categories up to 10,000; website
  display group names up to 100,000. Lists are cached and **updated roughly hourly**, ordered
  best-seller first (ordering can be up to an hour stale). Products without an accessible
  sales rank are excluded, and the sales-rank reference category is not always identified
  correctly, so some products may be misplaced.
- `category`: a category node ID (find via Category Search, the Deals page "Show API query",
  or Amazon directly), or a website display product-group name (the product object's
  `productGroup` / websiteDisplayGroupName field). Category Lookup with categoryId 0 lists all
  root categories.
- `range`: 0 = current rank (default), 30 / 90 / 180 = N-day average rank. Optional
  `month` + `year` request a historical monthly list (last 36 months, not the current month,
  cannot be combined with `range`). `variations` (0 = collapse to one per parent, 1 = all)
  controls variation ASINs. `sublist=1` ranks by sub-category (classification) rank instead of
  primary rank (cannot combine with range / month / year).
- For Deal Promoter this is a discovery/seed tool (e.g. seed a per-category watch-list of
  popular ASINs), not the per-cycle funnel. At 50 tokens a call, use sparingly and cache the
  lists.

### Marketplace selection (domainId)
- Every call targets one marketplace via `domainId`. Corrected against the official Keepa docs
  (the Request Best Sellers parameter table):
  1=US (com), 2=UK (co.uk), **3=DE (amazon.de)**, 4=FR, 5=JP (co.jp), 6=CA, 8=IT, 9=ES,
  10=IN, 11=MX (com.mx). Brazil is not applicable to the Best Sellers request. We use **3**
  for the Germany-first launch. **[VERIFIED]** `domainId: 3` returned German-language
  amazon.de deals (titles, € prices).
- Correction: an earlier draft of this file said 2 = DE. That was wrong; **2 is the UK** and
  **3 is Germany**. Any code or notes that used domainId 2 for amazon.de must use 3.

### Shape of the data (important: it is encoded, not plain JSON values)
- Prices and ranks live in a `csv[]` array, one entry per data type, indexed by a fixed
  `CsvType` table (AMAZON, NEW, USED, SALES rank, LISTPRICE, NEW_FBA, BUY_BOX_SHIPPING,
  COUNT_NEW, RATING, COUNT_REVIEWS, etc.; ~30+ types). Each entry is a flat
  `[time, value, time, value, ...]` series (time-keyed, not fixed-interval; gaps are normal).
- **Keepa Time** **[VERIFIED]**: timestamps are "Keepa minutes" = Unix-epoch-minutes minus a
  fixed offset of **21564000**. Convert to real time with `(keepaMinutes + 21564000) * 60000`
  ms; the Java framework wraps this as `KeepaTime.nowMinutes()` / conversion helpers. This
  epoch offset is the single most common stumbling block; budget for a small tested helper.
  Confirmed decoding live deal `lastUpdate`/`currentSince`/`creationDate` to sane timestamps.
- Prices are integers in the marketplace's minor unit (euro cents for amazon.de). A value of
  **-1 means "no data / out of stock"** and must be filtered, not treated as a 0 price.
  **[VERIFIED]** on deals — but note the sentinel differs by array (deals `avg`/`delta` use
  `-2`/`0`, see the Deals section). **[VERIFIED]** (exp 03) the `/product` **`csv` arrays use
  `-1` only** (every value slot scanned), resolving the earlier open question. Inside the
  `/product` `stats` object both appear: 1D type-indexed arrays use `-1`; offers-gated scalar
  subfields use `-2`.
- **[VERIFIED]** (exp 03) **Keepa-time decodes `/product` timestamps** too: `trackingSince`,
  `listedSince`, `lastUpdate`, `lastPriceChange`, and each `csv` point all decode to sane dates
  via `(min + 21564000) * 60000`.
- RATING is an int **0..50 (45 = 4.5 stars); divide by 10** **[VERIFIED]** (exp 03 — the ÷10
  formatter is locked in `formatStatValue`, range asserted `[0,5]`). NEW prices exclude
  shipping; the `*_SHIPPING` / FBM types include it. Buy Box is `BUY_BOX_SHIPPING`.
- **`*_SHIPPING` 3-wide stride PENDING (not verified):** csv types 7, 18, 19–29, 32 are
  `[time, price, shipping]` (3-wide), unlike the 2-wide `[time, value]` price/rank/count types.
  exp 03 could not exercise this — those csv slots are `null` without `offers` (which we avoid
  for cost). Needs a dedicated offers-probe. **`decodeCsv`'s flat 2-wide stride is therefore a
  known landmine for shipping-bearing types** in the PHP port. Also pending: the **2026-02-23
  pricing-definition change** (lowest-listing → landing price) — confirm impact in the offers-probe.
- The official Java framework ships a `ProductAnalyzer` with helpers like `getLast(csv,type)`
  and `calcWeightedMean(csv, now, days, type)`; the `/product` `stats` parameter computes
  min/max/avg server-side so we usually do not need to decode full history ourselves.
- The community Python wrapper decodes all of this (time + CSV + offers) for you; if we build
  in PHP we will reimplement these decoders (port the Java `ProductAnalyzer` + `KeepaTime`).

## 4. Feedback, gotchas, and reliability notes

- **Token starvation is the #1 real-world complaint.** Lower tiers refill slowly, and a
  naive "loop over ASINs with offers=on" job drains the bucket fast. Mitigations: read the
  live token balance/refill on every response and back off; never request `offers`/extra
  data unless required; batch ASINs per /product call; keep the expensive /product step only
  for deals that already survived cheap filters (our design).
- **Rate limiting is implicit via tokens**, not a separate HTTP 429 quota, so the discipline
  is "spend tokens you actually have." Plan a scheduler that paces to refill rate.
- **Learning curve is real and mostly about the encoding**: delta-encoded CSV arrays,
  the keepa-time epoch offset, -1 sentinels, and the type-index table trip up most newcomers.
  Decoding bugs (off-by-epoch, treating -1 as a price) are common. Use `stats` where possible
  to avoid hand-decoding.
- **Data freshness/lag**: history is excellent but not perfectly real-time. Update cadence
  varies by product popularity (hot items refresh often, long-tail items lag). A "current"
  price from history can be stale, which is exactly why Deal Promoter must re-validate the
  live price against the Amazon Creators API before publishing.
- **Support / docs**: docs are functional but terse and SPA-bound; the community leans on the
  forum threads (Browsing Deals, Request Products) and the Python wrapper for clarity.
  Support is responsive-but-small (effectively the founder/small team).
- **ToS on redistributing data**: Keepa restricts bulk redistribution/resale of its raw data.
  Publishing individual deal posts with our own affiliate links is a normal use, but we
  should not republish Keepa's historical datasets wholesale. CONFIRM the exact API terms
  before launch, especially around storing/redisplaying price history.

## 5. Other useful notes for the team

- **Official SDK**: Keepa publishes an official **Java** framework
  (github.com/keepacom/api_backend, Maven `com.keepa.api:backend` from
  https://keepa.com/maven/, actively maintained, releases through 2026). That repo is the
  authoritative source of truth for object shapes (Product, Deal, Stats), the `CsvType`
  table, `AmazonLocale` (domainId) enum, `KeepaTime`, and `ProductAnalyzer`. Even building in
  PHP, read this repo when porting decoders.
- **Community Python wrapper** (github.com/akaszynski/keepa, docs at keepaapi.readthedocs.io)
  is well-maintained, async-capable, and mirrors the real API; great as a reference even if
  we build in PHP. It handles token tracking, time/CSV decoding, deals, product_finder, and
  offers.
- **PHP**: no first-party PHP SDK. There are community PHP clients of varying quality, but
  expect to write a thin HTTP client plus our own decoders (keepa-time, delta-CSV, -1
  sentinel). Validate any third-party PHP lib carefully before relying on it. (Follow-up.)
- **Best practices to not burn tokens**:
  - Deals endpoint as the wide net (150/page, cheap); /product only for survivors.
  - Never enable `offers`/extra data unless a specific check needs it.
  - Batch multiple ASINs per /product call.
  - Prefer the `stats` parameter over pulling and decoding full history when you only need
    min/avg/current over a window.
  - Read tokensLeft / refillIn on every response and pace the scheduler to the refill rate;
    persist the last cursor so a token-exhausted run can resume.
  - Cache product results for a TTL so re-checks within the same window cost nothing.

## Follow-up research questions

- Exact, current token costs: /product (with and without `stats`, `offers`, `rating`),
  straight from keepa.com/#!api on a real account. (Deals call **answered: 5/page** —
  `experiments/02-deals/FINDINGS.md`; the /token check is free — `01-key-and-tokens`.)
- Exact subscription tiers and EUR prices, and the precise per-minute refill rate per tier;
  pick the smallest tier that sustains our planned daily deal volume.
- Full deals-query semantics for amazon.de: best `priceTypes` + `deltaPercentRange` +
  `salesRankRange` combination to surface genuine, popular drops with low noise. (Query/response
  shape now mapped in `experiments/02-deals/FINDINGS.md`; open part is the **glitch-guard
  tuning** — `sortType=4` floats price-spike artifacts, so find bounds/`salesRankDrops*` combo
  that filters them.)
- Authoritative API Terms of Service: what we may store, cache, and redisplay (price-history
  redistribution limits) when posting deals publicly.
- Whether any community PHP client is good enough to adopt, or whether we write our own thin
  client + decoders (keepa-time, delta-CSV, -1 handling, type-index table).
- How fresh deals/prices really are for amazon.de mid-tier products, to size the
  re-validation window against the Amazon Creators API.
- Whether the deals endpoint supports a "since last poll" delta so we only process new drops
  rather than re-scanning pages.

## Sources

- Keepa API page (SPA, manual review needed): https://keepa.com/#!api
- Browsing Deals forum thread: https://keepa.com/#!discuss/t/browsing-deals/338
- Request Best Sellers doc (official; pasted from https://keepa.com/#!discuss/t/best-sellers/1298
  since the SPA does not scrape) -> source of the corrected domainId table and the 50-token cost
- Official Java backend / model classes: https://github.com/keepacom/api_backend
- Community Python wrapper (overview + queries): https://keepaapi.readthedocs.io/en/latest/
  and https://keepaapi.readthedocs.io/en/latest/product_query.html
- Wrapper source/issues: https://github.com/akaszynski/keepa
- Community sentiment / discussion: https://www.reddit.com/r/Keepa/
- Token-cost / scale-of-pulls writeup (offers calls expensive, batching, ~10h for 10k ASINs):
  https://basil-latif.medium.com/scraping-amazon-offer-data-at-scale-how-i-pulled-10-000-asins-with-python-keepa-api-b2ef476cd039
- API pricing tiers (EUR, third-party guides, CONFIRM on live page):
  https://fbamultitool.com/keepa-subscription-pricing-quick-guide-for-amazon-sellers/ and
  https://www.pangolinfo.com/keepa-alternative-pangolinfo-api-price-tracking-2/
- Keepa-time / rate-limit note: https://developer.sellerassistant.app/keepa/get-product
- Community Keepa MCP server (token-cost notes): https://github.com/cosjef/keepa_MCP

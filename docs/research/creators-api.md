# Amazon Creators API Research (for Deal Promoter)

High-level research brief on the Amazon Creators API, oriented toward the Deal Promoter
pipeline (find amazon.de price-drop deals via Keepa, deep-validate survivors against the
Creators API, publish affiliate links to WhatsApp). This is the **validation and link-building
stage** of the funnel: we never publish a price we have not just confirmed live here, and the
affiliate link we post is the one this API returns, not a hand-built URL.

> Note on naming and timing: the thing the product spec calls the "Amazon Creators API" is the
> official **successor to the Product Advertising API (PA-API) 5.0**. Amazon is actively
> migrating everyone off PA-API 5.0; its docs site now carries a deprecation banner. Operations
> (`GetItems`, `SearchItems`), the resource-selection model, and the response shapes are carried
> over almost unchanged, but **authentication and the endpoint changed** (OAuth 2.0 bearer
> tokens against a single global host, not AWS SigV4 against regional hosts). Several exact field
> behaviours below are documented on the still-most-complete PA-API 5.0 reference pages and
> cross-linked from the Creators docs. Anything marked CONFIRM should be verified on a real
> account / the Scratchpad, because Amazon is changing this surface right now.

## 1. What it is

- The Creators API is a REST/JSON API giving programmatic access to Amazon's product catalogue
  (price, availability, offers, images, browse nodes) for Amazon Associates partners. It is the
  data source behind affiliate deal sites, price widgets, and comparison tools.
- It is the rebranded, re-platformed successor to **PA-API 5.0**. Same Associates program
  backing, same partner tags, same catalogue. Think of it as an auth + transport modernization
  rather than a new affiliate program. Official docs:
  `https://affiliate-program.amazon.com/creatorsapi/docs/`.
- **Deprecation timeline (CONFIRM, from Amazon migration notices, dates have shifted between
  notices):** PA-API 5.0 recommended-migration cutoff around **2026-04-30**; PA-API v5 endpoint
  retirement around **2026-05-15**. PA-API V1 `Offers` data already retired (Jan 2026);
  **offers now live only as `OffersV2`** on the Creators platform. Because we are building in
  2026, build against the **Creators API**, not the SigV4 PA-API.
- For us the relevant value is narrow and critical: given an ASIN that Keepa flagged as a price
  drop, the Creators API tells us the **live buy-box price, availability, condition, merchant,
  Amazon's own deal flag, and the ready-to-post affiliate URL**. It is the gate between "Keepa
  says cheap" and "we publish."

## 2. Access, authentication, and the keep-access rule

### Eligibility (background only, we already have access)
- Must be enrolled in Amazon Associates for the target marketplace. Current Creators docs state
  a gate of **>= 10 qualifying sales in the past 30 days** (older PA-API lore said 3 sales /
  180 days; treat 10/30 as current, CONFIRM per region). Only the primary account owner
  registers. Out of scope for this cycle per the product spec.

### Credentials
- Register under Associates Central -> Tools -> Creators API: Create Application, then Create
  Credential. This yields a **Credential ID**, **Credential Secret**, and a **Version** (the
  Version encodes which region/auth path Amazon issued and matters for the token endpoint and
  headers). The secret is shown once. **Old AWS access key / secret key do NOT work.**

### Auth flow (OAuth 2.0 client-credentials, NOT SigV4)
- Exchange Credential ID + Secret for a short-lived **bearer token** (`expires_in` ~3600s) at a
  **region-specific token endpoint** chosen by your credential Version, then call the product
  API with that token. There is no per-request canonical-request signing, no `X-Amz-Date`,
  no regional signing key.
- For our **EU / amazon.de** project the credential Version is **CONFIRMED 3.2 → the
  Login with Amazon (LWA) path** (experiment 06, 2026-05-31). Note the credential page
  writes it with a leading `v` (`v3.2`); strip non-digits before deriving the major.
  The v2.2 (Cognito) path below is retained for reference / other accounts:
  - v2.2 token endpoint (Cognito):
    `creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token`, scope `creatorsapi/default`
    (single slash), form-encoded body; token endpoint is rate-limited (~300 token requests /
    5 min, so **cache the token**, do not fetch per request).
  - v3.2 token endpoint (Login with Amazon): `api.amazon.co.uk/auth/o2/token`, scope
    `creatorsapi::default` (double colon), JSON body.
- **Credentials are region-scoped, not marketplace-scoped:** one EU credential set covers
  amazon.de plus the other EU marketplaces; you pick the marketplace per request.
- Product-request headers:
  - `Authorization: Bearer <token>, Version <n>` (the `, Version <n>` suffix is required for
    v2.x credentials; omit for v3.x).
  - `Content-Type: application/json`
  - `x-marketplace: www.amazon.de`

### The keep-access rule (real operational risk)
- Access is revoked after a **consecutive 30-day period with no qualifying referred sales**, and
  restored ~2 days after referred sales ship again. A validation pipeline that does not itself
  drive purchases will eventually be cut off and start getting throttle/eligibility errors. Our
  WhatsApp posting is what keeps the API alive, so the two halves of the system depend on each
  other. CONFIRM the exact qualifying-sales count (community reports 3 or 10, region-dependent;
  error surfaces as `AssociateNotEligible`).

## 3. Parts of the API essential for us

> **CONFIRMED by experiments 06–07 (2026-05-31), from a live call + the official
> SDK.** The PA-API 5.0 cross-links below were misleading on transport/casing —
> use these confirmed values:
> - Product endpoint: **`POST https://creatorsapi.amazon/catalog/v1/getItems`**
>   (single global host on the dotless `.amazon` gTLD; NOT `webservices.amazon.com/paapi5`).
> - Marketplace is the **`x-marketplace` header** (`www.amazon.de`), **not** a body field;
>   there is no `itemIdType` in the body. Body keys are lowerCamelCase: `partnerTag`
>   (required, validated against the credential's store), `itemIds`, `resources`.
> - **Resource strings are lowercase-dotted** (`itemInfo.title`,
>   `offersV2.listings.price`, …), NOT PA-API PascalCase.
> - **Response envelope is lowerCamelCase**: `itemsResult.items[]`, `errors[]`, and
>   per-item `asin` / `detailPageURL`.
> - An invalid/unmapped `partnerTag` → HTTP 400 `{type: ValidationException,
>   reason: InvalidPartnerTag, fieldList:[{name:"partnerTag"}]}` (request-level,
>   distinct from per-item `errors[]`).
> - **`detailPageURL` already carries our affiliate link** (proven live):
>   `…/dp/<asin>?tag=<our-tag>&linkCode=ogi&th=1&psc=1`. Use it verbatim; no link-building.
> - **Per-item `errors[]` entries are `{code, message}` only — NO `asin` field.** A
>   bad ASIN gives `code: "InvalidParameterValue"` (NOT `ItemNotAccessible`) with the
>   ASIN only in the message string. To map failures back to inputs, **diff requested
>   ASINs against `itemsResult.items[].asin`** — you cannot reconcile errors by field.
> - Auth (our v3.2 LWA credential): `Authorization: Bearer <token>`, no Version suffix.

### `GetItems` (our hot path)
- Looks up known ASINs and returns exactly the attributes you request. This is the right
  primitive for deal validation (we already have the ASIN from Keepa; we want its live state).
- Input: `itemIds` (**up to 10 ASINs per call**, the batch limit), `partnerTag`, `marketplace`
  (`www.amazon.de`), `resources` (array selecting which fields to return), optional `itemIdType`
  (default ASIN). Note Creators API uses **lowerCamelCase** param names; the old PascalCase
  (`ItemIds`, `Resources`) is rejected.
- Output: `ItemsResult.Items[]`, each with top-level `ASIN`, top-level `DetailPageURL`, and the
  requested resource blocks. **Invalid/inaccessible ASINs land in a separate `Errors[]` block,
  not in `Items`, and order is not guaranteed** -> always reconcile results back to input by the
  `ASIN` field, never by position, and read `Errors[]`.
- **Batching is the main throughput lever:** one call with 10 ASINs counts as **1 transaction**,
  not 10 (see rate limits). Chunk survivors into groups of 10.

> **CONFIRMED by experiment 08 (2026-05-31), live amazon.de.** The field names in
> this section are SDK-derived PascalCase; **the live response is lowerCamelCase end
> to end** — `offersV2.listings[].{price:{money,savingBasis,savings},condition,
> availability,merchantInfo,dealDetails,isBuyBoxWinner,violatesMAP}`. Map names
> accordingly. Settled here:
> - **Discount signals confirmed present**: a Limited-time deal returned
>   `price.savingBasis` (was price, `savingBasisType: LIST_PRICE`) + `price.savings`
>   (`{money, percentage}`) + `dealDetails`; a non-deal item had none of the three.
>   `savingBasisType` is the gameable `LIST_PRICE`, so the real-drop gate stays
>   `price.money` vs the Keepa baseline — savings %/dealDetails are corroboration.
> - **Multiple listings of different conditions return, and the cheapest is NOT the
>   buy box** — pick `isBuyBoxWinner === true`, never `listings[0]`/min-price.
> - **"OffersV2 only returns NEW" is REFUTED** — a Used/LikeNew listing came back
>   (non-buy-box). Gate on `buyBox.condition.value === "New"`.
> - **amazon.de "Amazon" seller id = `A3JWKAKR8XB7XF`** (use for the sold-by-Amazon
>   gate, since there is no FBA boolean). `violatesMAP` exists and is checkable.
> - **No rate-limit headers** were observed on the GetItems response.

### `OffersV2` resource (where the deal truth lives)
- `OffersV2` replaces the deprecated `Offers`. You request it at a coarse level and get whole
  structs back. The resource strings (put these in `resources`) we care about:
  - `OffersV2.Listings.Price` -> the live price struct (now price, was price, savings).
  - `OffersV2.Listings.Condition` -> New / Used / Refurbished / etc.
  - `OffersV2.Listings.Availability` -> in-stock state.
  - `OffersV2.Listings.MerchantInfo` -> seller name / id.
  - `OffersV2.Listings.DealDetails` -> Amazon's own deal flag (see below).
  - `OffersV2.Listings.IsBuyBoxWinner` -> which listing is the featured offer.
  - `OffersV2.Listings.Type` -> `LIGHTNING_DEAL`, `SUBSCRIBE_AND_SAVE`, or absent.
- **A single item can return multiple listings** (e.g. a Prime-exclusive deal listing plus an
  all-customers listing). Do not assume the first one. **Identify the live buy-box price by the
  listing where `IsBuyBoxWinner == true`.**

### Price / condition / availability / merchant fields (the deal gate inputs)
- **Price** (`OffersV2.Listings.Price`):
  - `Money` = `{ Amount, Currency, DisplayAmount }` -> the **now / current** price.
  - `SavingBasis` -> the **was / strikethrough** reference: `Money`, `SavingBasisType`
    (`LIST_PRICE` / `LOWEST_PRICE` / `WAS_PRICE` / ...), and a label.
  - `Savings` -> `{ Money, Percentage }` -> Amazon's own discount claim.
  - `SavingBasis`/`Savings` appear only when there is a reference/saving; for validating a real
    drop, compare `Price.Money.Amount` against our Keepa-derived baseline and use
    `Savings.Percentage` as Amazon's claimed discount.
- **Condition**: `Value` (New / Used / Refurbished / Unknown) plus `SubCondition` / note. For our
  New-only policy, gate on `Condition.Value == "New"`. CONFIRM: a 2024 OffersV2 changelog said it
  "only returns NEW" currently, which is fine for us but means we cannot use OffersV2 to surface
  Used offers.
- **Availability**: `Type` is one of `IN_STOCK`, `IN_STOCK_SCARCE`, `OUT_OF_STOCK`, `PREORDER`,
  `LEADTIME`, `AVAILABLE_DATE`, `UNAVAILABLE`, `UNKNOWN`. Gate on `IN_STOCK` (optionally also
  `IN_STOCK_SCARCE`). Plus `Message`, `MaxOrderQuantity`.
- **MerchantInfo**: `Name` and `Id` of the seller. There is **no explicit FBA / sold-by-Amazon
  boolean in OffersV2** (V1's `DeliveryInfo.IsAmazonFulfilled` / `IsPrimeEligible` was dropped).
  To approximate "sold by Amazon" we must match `MerchantInfo.Id`/`Name` against Amazon's seller
  ids per marketplace (we need the amazon.de Amazon seller id). This FBA gap is a real
  limitation for any "trusted merchant" gate; CONFIRM whether Creators restores a fulfillment
  field.
- **`ViolatesMAP`** (boolean) can appear on a listing: if true Amazon hides the price until
  cart/checkout, so such an item may have no usable advertised price; treat as not postable.

### `DealDetails` (Amazon's own "is this a deal" signal, the cleanest one available)
- **`DealDetails` is only present when the listing is an actual deal.** Requesting
  `OffersV2.Listings.DealDetails` and checking presence on the buy-box listing is exactly the
  "Amazon flags it as a deal" confirmation the product spec wants as a bonus signal for the deal
  gate. Fields:
  - `AccessType`: `ALL` / `PRIME_EARLY_ACCESS` / `PRIME_EXCLUSIVE`.
  - `Badge`: display label, e.g. `Limited time deal`, `Black Friday Deal`, `Ends in`.
  - `StartTime` / `EndTime` (UTC): deal window (may be absent for open-ended deals).
  - `PercentClaimed`: how much capacity is consumed (not on all deal types).
  - `EarlyAccessDurationInMilliseconds`: Prime-only head start.

### `DetailPageURL` (the affiliate link we publish)
- Returned **by default on every item** (top-level, no special resource string needed). It
  **already carries our partner tag and `linkCode`**, e.g.
  `https://www.amazon.de/dp/ASIN?tag=ourtag-21&linkCode=ogi&...`. This is the URL we post; the
  product spec is right that we must not hand-build links.
- Sub-tags: the response stamps the single `partnerTag` we sent; it does **not** auto-add
  `ascsubtag`. If we want per-channel / per-campaign sub-tags we append `ascsubtag=...` to the
  returned URL ourselves. CONFIRM this is permitted for our account type and whether the Creators
  request supports any per-call sub-tag.

### Other operations (not our hot path)
- `SearchItems` (keyword/category discovery, up to 10 results), `GetVariations` (parent/child
  variation ASINs), `GetBrowseNodes` (category-tree lookup, batchable). We mainly use `GetItems`;
  `GetBrowseNodes` may help later to key a category->commission-rate table.

## 4. Rate limits, freshness, gotchas, reliability

- **Rate limits (dynamic quota, from the official API Rates page):**
  - New account starts at up to **1 TPS** and **8,640 TPD** for the first 30 days.
  - Quota then scales daily with shipped-item revenue from API-generated links over the prior 30
    days: **+1 TPD per 5 cents**, **+1 TPS per $4,320**, TPS **capped at 10**.
  - **One call = one transaction regardless of payload**, so a 10-ASIN GetItems is 1 transaction.
    Batching to 10 is the single biggest efficiency lever and lines up perfectly with our
    "deep-validate only survivors" funnel.
- **Throttling**: exceeding TPS or TPD returns **HTTP 429 `TooManyRequests`** (also returned when
  access is revoked). TPD exhaustion 429s even when under TPS. Use exponential backoff with
  jitter (Amazon gives no required constants, CONFIRM ours) and spread calls across the day; our
  cron cadence plus per-cycle caps already smooth this.
- **Caching rules (official, and a freshness hint):** Amazon permits caching **Offers /
  BrowseNodeInfo for 1 hour** and most other data for 1 day; caching customer-derived data is
  prohibited. The 1-hour offers allowance is a strong signal that **prices are "recent and
  authoritative-ish, possibly up to ~1h stale," not guaranteed live to the second.** This is
  exactly why we validate immediately before posting and keep the window tight; if we need
  exact-moment pricing we re-fetch right before publishing.
- **Partial responses**: good ASINs return, bad ones go to `Errors[]` (`ItemNotAccessible`,
  `InvalidParameterValue`, ...). Buy box / offers can be entirely absent for a valid ASIN (no
  eligible offer), so price fields may be missing; treat missing price as "skip, not a deal,"
  fail-safe per the product spec.
- **Common failure modes**: 429 (throttle or revoked access), `AssociateNotEligible` (keep-access
  rule), OAuth token expiry if not cached, missing buy box / price, `ViolatesMAP` hidden price.
- **Commission rates are NOT in the API.** Neither PA-API nor Creators returns per-category
  affiliate fee rates. Rates come from the **amazon.de Associates (Partnernet) fee schedule** and
  vary by category and over time. If we want to rank deals by expected commission we must
  maintain our **own category->rate table for .de**, keyed by browse-node/category, and refresh
  it manually. CONFIRM current .de rates from Partnernet.

## 5. Other useful notes for the team

- **Official PHP SDK**: Amazon ships PHP SDK *code* but does **not** publish to Packagist. The
  de-facto installable mirror, used widely and currently maintained, is
  **`thewirecutter/paapi5-php-sdk`** (GitHub `github.com/thewirecutter/paapi5-php-sdk`, Apache-2.0,
  maintained by NYT/Wirecutter, described as a near-identical public copy of Amazon's Creators
  API PHP SDK). `composer require thewirecutter/paapi5-php-sdk`.
- **Version split matters:** SDK **v1.x** = classic PA-API SigV4 (`Amazon\ProductAdvertisingAPI\v1\`);
  SDK **v2.0.0+** = Creators API OAuth 2.0 (`Amazon\CreatorsAPI\v1\`, manages OAuth via an
  `OAuth2TokenManager`). **Use v2.x for new work.** Requires **PHP 8.1+** and Guzzle 7.3+.
- **Token caching gotcha**: the SDK's OAuth token cache is **in-memory per `DefaultApi`
  instance**, so a per-request PHP-FPM model re-fetches a token every request. We run as a CLI
  cron cycle so a single instance lives for the whole cycle (fine), but if any HTTP surface later
  calls the API, back the token with a PSR-16 cache (Redis/file), TTL ~3300s (55 min) vs the
  60-min token life. The SDK README has sample code for this.
- **GetItems sketch (v2.x / Creators, amazon.de):**
  ```php
  use Amazon\CreatorsAPI\v1\Configuration;
  use Amazon\CreatorsAPI\v1\com\amazon\creators\api\DefaultApi;
  use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsRequestContent;
  use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsResource;

  $config = new Configuration();
  $config->setCredentialId('<ID>');
  $config->setCredentialSecret('<SECRET>');
  $config->setVersion('<VERSION>'); // 2.2 or 3.2 for EU

  $api = new DefaultApi(null, $config);
  $request = new GetItemsRequestContent();
  $request->setPartnerTag('ourtag-21');
  $request->setItemIds(['B0...', 'B0...']); // up to 10
  $request->setResources([
      GetItemsResource::OFFERS_V2_LISTINGS_PRICE,
      GetItemsResource::OFFERS_V2_LISTINGS_CONDITION,
      GetItemsResource::OFFERS_V2_LISTINGS_AVAILABILITY,
      GetItemsResource::OFFERS_V2_LISTINGS_MERCHANT_INFO,
      GetItemsResource::OFFERS_V2_LISTINGS_DEAL_DETAILS,
      GetItemsResource::OFFERS_V2_LISTINGS_IS_BUY_BOX_WINNER,
      GetItemsResource::ITEM_INFO_TITLE,
  ]);
  $response = $api->getItems('www.amazon.de', $request);
  ```
- **Validation logic per ASIN (maps directly to the spec's deal gate):** pick the
  `OffersV2.Listings[]` entry where `IsBuyBoxWinner == true`; require `Condition.Value == "New"`,
  `Availability.Type == "IN_STOCK"`, and `MerchantInfo` matches Amazon (or our trusted-merchant
  set); read `Price.Money` (now) and `Price.SavingBasis.Money` (was) and `Savings.Percentage`;
  treat presence of `DealDetails` (and/or `Type == LIGHTNING_DEAL`) as Amazon's deal bonus
  signal; confirm the live price is within tolerance of Keepa's claimed drop; then publish the
  item's `DetailPageURL`. Reconcile by `ASIN`, check `Errors[]`, and skip-and-retry on anything
  missing.
- **Scratchpad**: build and test live requests (and confirm exact field behaviour for .de) at
  `https://webservices.amazon.com/paapi5/scratchpad/`. Best way to settle the CONFIRM items.

## Follow-up research questions

- Confirm our credential **Version** (2.2 vs 3.2) and therefore the exact token endpoint, scope
  string, and whether the `, Version <n>` Authorization suffix is needed.
- Confirm the **amazon.de Amazon seller id(s)** so the MerchantInfo "sold by Amazon" check is
  reliable, and decide our trusted-merchant policy given OffersV2 has no FBA flag.
- Confirm whether OffersV2 condition/merchant filtering works on .de or is still "New only" /
  "no Amazon-only filter," and whether that affects our gate.
- Confirm `ascsubtag` / per-channel sub-tag support on the returned `DetailPageURL` for our
  account type (needed if we ever route multiple channels and want per-channel attribution).
- Confirm whether a plain returned `DetailPageURL` renders a preview card in a WhatsApp channel
  (shared open question with the WAHA work).
- Pin the real **deprecation/cutover dates** and make sure we are on the Creators (OAuth) path,
  not the SigV4 PA-API, before the retirement.
- Decide retry/backoff constants and per-cycle GetItems budget given our TPS/TPD and Keepa
  survivor volume.
- Build the **amazon.de category -> commission-rate table** (from Partnernet, not the API) if we
  rank deals by expected earnings.

## Sources

- Creators API docs (intro, operations, prerequisites):
  https://affiliate-program.amazon.com/creatorsapi/docs/
- Creators API "Using cURL" (token endpoints, headers, base URL, examples):
  https://affiliate-program.amazon.com/creatorsapi/docs/en-us/get-started/using-curl
- Creators API migration guide (PA-API vs Creators differences):
  https://affiliate-program.amazon.com/creatorsapi/docs/en-us/migrating-to-creatorsapi-from-paapi
- GetItems operation (input, batch limit, response, errors):
  https://webservices.amazon.com/paapi5/documentation/get-items.html
- OffersV2 full field spec (resource strings, Price/Condition/Availability/MerchantInfo/DealDetails):
  https://webservices.amazon.com/paapi5/documentation/offersV2.html
- Prime Exclusive Deal Pricing use case (real GetItems + OffersV2 buy-box + DealDetails logic):
  https://webservices.amazon.com/paapi5/documentation/use-cases/prime-exclusive-deal-pricing.html
- API Rates (TPS/TPD, dynamic quota, 30-day rule, 429):
  https://webservices.amazon.com/paapi5/documentation/troubleshooting/api-rates.html
- Best Programming Practices (batching, TPS smoothing, cache TTLs):
  https://webservices.amazon.com/paapi5/documentation/best-programming-practices.html
- Error messages (429 etc.):
  https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html
- PA-API 5.0 Common Request Parameters (legacy DE host webservices.amazon.de, region eu-west-1):
  https://webservices.amazon.com/paapi5/documentation/common-request-parameters.html
- Official PHP SDK mirror: https://github.com/thewirecutter/paapi5-php-sdk and
  https://packagist.org/packages/thewirecutter/paapi5-php-sdk
- Scratchpad (test live requests): https://webservices.amazon.com/paapi5/scratchpad/
- Auth-layer migration deep-dive (SigV4 vs OAuth, endpoints, dates):
  https://dev.to/th3nate/amazon-pa-api-v5-is-shutting-down-april-30-2026-here-is-what-changes-at-the-auth-layer-22ek
- Migration / region-credential notes:
  https://www.keywordrush.com/blog/amazon-creator-api-what-changed-and-how-to-switch/
- 429 / keep-access community notes:
  https://www.keywordrush.com/blog/fix-amazon-paapi-too-many-requests/ and
  https://www.keywordrush.com/blog/amazon-pa-api-associatenoteligible-error-is-there-a-new-10-sales-rule/

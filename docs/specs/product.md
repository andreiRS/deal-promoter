# Deal Promoter

## Problem

There is recurring affiliate revenue available in publishing genuinely good Amazon deals to an audience that wants them, but capturing it by hand does not scale and is unreliable. Finding real price drops, confirming they are actually buyable and commission-worthy, building correct affiliate links, and posting them on a steady cadence is tedious, error-prone, and easy to abandon. Manual posting also tends to drift into spam (reposting the same item, flooding subscribers), which erodes the audience the revenue depends on.

The value chain: end users join a WhatsApp channel that surfaces Amazon deals every few minutes; for every click or purchase through a correctly tagged affiliate link, we earn a commission. The product's job is to find, validate, manage, and publish those deals automatically and reliably, so the channel stays trustworthy and the affiliate links keep earning.

**Confidence:** assumption
**Sources:** Affiliate-deal channels are a known, working model; that this specific pipeline earns at a worthwhile rate for us is not yet proven and depends on deal quality, audience growth, and conversion, none of which we have measured.

## Solution

Build a small set of cooperating applications in a PHP monorepo, prototyped together but kept reliable through clear seams and fail-safe behavior. The first and only deliverable in this cycle is the headless **deal pipeline**: a scheduled process that runs a clean cycle end to end.

Each cycle:

1. **Source candidates** from Keepa's deals (browsing deals) endpoint, filtered by an editable criteria config (discount percent, price band, sales rank, categories, rating). One cheap call returns up to 150 recently changed deals.
2. **Deduplicate** against previously posted deals in Postgres, accessed through a small storage interface (so the query layer stays swappable and testable).
3. **Validate survivors** against the real Amazon Creators API (`GetItems` with `OffersV2`): live buy-box price, availability, condition, merchant, and Amazon's own `dealDetails`. The affiliate link is the API-provided `detailPageURL`, which already carries the partner tag, not a hand-built URL.
4. **Apply the deal gate** to decide what is "truly a deal" worth publishing (exact gate is an open question below).
5. **Publish, paced**, to a single WhatsApp channel as plain-text product links via WAHA (a Dockerized WhatsApp HTTP bridge running as a sidecar), best deals first, capped per cycle with spacing and an hourly ceiling.
6. **Record** what was posted (ASIN, price, timestamp) to drive dedup and repost decisions. Over time this recorded price history also becomes our most trustworthy discount baseline, immune to Keepa's polluted averages and to seller-set MSRP (see the deal-gate open question).

The pipeline is an idempotent CLI command (`bin/run-cycle`) invoked by system cron every X minutes, guarded by a run-lock so a slow cycle cannot overlap the next tick. Behavior is fail-safe: a deal is published only when every check passes; on any dependency error the item or cycle is skipped and retried next tick; before posting, the WAHA session is confirmed `WORKING`, and a dropped session raises an alert rather than silently posting nothing.

Cross-cutting integration code (Keepa client, Amazon Creators client, WAHA client, storage interface) lives in a shared local Composer package so the later apps (admin, public landing, API providers) reuse it without duplication.

## Scope

### In scope

- As the operator, I can run a single pipeline cycle from a CLI command so that a cron schedule can drive it every X minutes.
- As the operator, I can define deal-search criteria (discount percent, price band, sales rank, categories, rating) in editable config so that tuning thresholds needs no code change.
- As the system, I fetch candidate deals from Keepa's deals endpoint each cycle so that I always work from fresh price drops.
- As the system, I skip any candidate already posted (subject to the repost policy) so that the channel does not repeat itself.
- As the system, I validate each surviving candidate against the Amazon Creators API for live price, availability, condition, merchant, and deal status so that I never post a stale or unbuyable price.
- As the system, I publish qualifying deals to one WhatsApp channel as plain-text links carrying the affiliate tag so that clicks and purchases earn commission.
- As the system, I pace posting (cap per cycle, randomized spacing, hourly ceiling, best deals first) so that subscribers are not flooded and the WhatsApp account does not look botty.
- As the system, I behave fail-safe (post only when all checks pass, skip-and-retry on errors, verify the WAHA session before posting) so that a partial failure never produces a bad or duplicate post.
- As the operator, I receive an alert when the WhatsApp session has dropped or dependencies repeatedly fail so that I can re-pair or intervene.
- As a future maintainer, I find marketplace, channel, and affiliate tag modeled as configuration so that adding marketplaces or channels later is config, not a rewrite.

### Out of scope

- Any web interface in this cycle: no admin UI, no public landing page, no API-provider apps (these are future deliverables, scaffolded later on top of the pipeline).
- Multiple marketplaces at runtime: amazon.de only now, though modeled (domain, tag, currency, locale) so more can be added.
- Multiple channels and category-to-channel routing: one channel now, modeled as config for later.
- Image or rich-media posts: plain-text links only.
- Conversion and earnings analytics, dashboards, and reporting.
- Bootstrapping Amazon API eligibility: API access already exists, so the 10-qualifying-sales gate is not part of this work.

## Success Criteria

- A cron-driven cycle runs end to end unattended: Keepa fetch, dedup, Amazon validation, gated publish to the channel, and recording, with no manual steps.
- Every published post is a New, in-stock, buyable item at the live price shown, linking through a working affiliate-tagged URL (no stale prices, no dead or untagged links).
- The same deal is not spammed: dedup and the repost policy demonstrably suppress repeats across cycles.
- Posting stays within the configured pacing limits (per cycle, spacing, hourly ceiling) over a sustained run.
- No partial or corrupt posts occur on dependency failure; failed cycles skip cleanly and recover on the next tick.
- A dropped WhatsApp session is detected before posting and raises an alert; the pipeline posts nothing until it is re-paired.
- Search criteria can be changed via config and take effect on the next cycle without a code change or redeploy.

## Constraints

- Technology: PHP across services, Symfony for all services and web apps (Symfony Console for the headless pipeline CLI, the full framework for the later HTTP surfaces), Postgres as the datastore from the start (run as a Docker service), Doctrine (ORM with migrations) for persistence, accessed behind a storage interface, Docker for all components, `bun` (not `npm`) for any JS tooling.
- WhatsApp delivery is channels-only (`@newsletter`) via WAHA as a sidecar. WAHA drives a logged-in WhatsApp account through WhatsApp Web, which is unofficial and carries a ban risk; the architecture must keep this fragile component isolated and the rest of the system fail-safe around it.
- Amazon validation must use the Creators API (the PA-API successor); the official PHP SDK is available. Affiliate links must be the API-provided `detailPageURL`.
- Keepa access is metered by tokens; the design must filter hard on the cheap deals call and deep-fetch per-ASIN history only for survivors.
- Repo is a monorepo (`apps/` plus a shared local Composer package), one root `docker-compose`, each app independently runnable.

## Open Questions / Risks

- **Deal gate definition.** What makes a candidate "truly a deal" worth posting. Leading option: New, in stock, trusted merchant, buy-box winner, and live price within tolerance of Keepa's claimed discount, with `dealDetails` as a bonus signal. Alternatives considered: require Amazon's `dealDetails` flag (higher quality, far fewer posts); or treat a confirmed price drop as sufficient (more volume, more risk).
  - **Refined by exp09 (the live end-to-end run).** Live Creators validation confirms a price is *real and buyable* but NOT the *size* of the discount: the "% off" rests on a baseline, and every baseline we have is unreliable. The leading option's "within tolerance of Keepa's claimed discount" measures the drop against Keepa's `avg90`, which is persistently glitch-polluted (long out-of-stock + third-party gouging). On the live run it published fake discounts: an item read 80% off avg90 but was really ~12% off (Amazon's own `WAS_PRICE`), and another read 84% off while Amazon flagged no deal at all. Amazon's `savings.percentage` is NOT a clean substitute either: on 3 of 4 items that carried it, the basis was the gameable `LIST_PRICE` (MSRP) claiming 81–88% off. The only trustworthy magnitude evidence is `dealDetails` present and/or `savingBasisType == "WAS_PRICE"` (Amazon's recent actual price), which was rare (1 of 10 validated items on that page).
  - **Decision still open: where to set the volume-vs-trust dial.** (a) *Strict*: publish only when Amazon attests via `dealDetails`/`WAS_PRICE` (max trust, low volume, ~1/10 here). (b) *Loose* (the current lean): publish on a price drop, attestation optional (max volume, lets fake discounts through). (c) *Middle (leaning)*: publish on a trustworthy magnitude, advertise the conservative `min(Keepa-derived, Amazon-attested)`, claim a headline "% off" only when Amazon attests it (`dealDetails`/`WAS_PRICE`), and require a *stable* Keepa baseline (`outOfStockPercentage90` low, avg30≈avg90≈avg180) before trusting a Keepa-only magnitude; otherwise post without a "% off" claim or skip. This needs no extra API call (the fields are already in the GetItems response). This is a product/brand call, not a technical one.
- **Repost policy.** When an already-posted ASIN may be posted again. Leading option: post once, then a cooldown window, then allow a re-post only on a meaningful further drop below the last posted price. Alternatives: once ever (hard dedup); time-window only.
- **Alert channel.** Where critical alerts go. Leading option: a Telegram bot (instant phone push, and it survives even when WAHA itself is down). Alternatives: email; log-only; a separate WAHA chat.
- **Post text template.** Exact fields in each plain-text post (title, old-to-new price, percent off, affiliate disclosure line). The affiliate disclosure is also a legal/ToS research item.
- **Overflow handling.** When a cycle qualifies more deals than the per-cycle cap, whether to carry the remainder to the next cycle (dedup covers it) or drop them. Leaning carry.
- **WAHA reliability and ban risk.** Session-drop frequency, channel message-rate limits, ban-risk mitigation, and whether WAHA Core suffices or WAHA Plus is needed. This is the single most fragile dependency.

## Research Backlog

To resolve before or during build:

- Keepa token economics and refresh interval, and exact deal-object fields.
- `offersV2.listings.dealDetails` and `merchantInfo` schemas.
- Creators API rate limits and whether `detailPageURL` tag/linkCode supports per-channel sub-tags.
- A DE browse-node to commission-rate table (rates are not exposed by the API).
- Conversion/earnings attribution strategy via Associates reporting and tracking IDs.
- ~~Whether a plain Amazon link renders a preview card in a WhatsApp channel.~~ **Confirmed working** — a plain Amazon link renders a preview card in a WhatsApp channel.
- Amazon Associates disclosure requirements and Keepa/WhatsApp ToS exposure.

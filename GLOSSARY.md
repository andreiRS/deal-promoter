# Glossary

The canonical vocabulary for Deal Promoter. One meaning per term, defined by its relationships and boundaries (what it connects to, what it is *not*); no implementation detail. Open decisions live in [`docs/specs/product.md`](docs/specs/product.md), not here.

## Affiliate Link

The tagged Amazon URL we publish; clicks and purchases through it earn commission. It is **always** the Creators API's `detailPageURL`, which already carries our partner tag, never a hand-built URL.

Not a bare product URL: a link without our tag earns nothing.

## Already-Posted Guard

The check that suppresses a raw [Candidate](#candidate) we have already posted, so the [Channel](#channel) never repeats itself. Checked against [Recorded Price History](#recorded-price-history) and governed by the [Repost Policy](#repost-policy) (which decides when a repeat is allowed).

One of the rejection checks a raw candidate must clear, alongside the [Pre-filter](#pre-filter). The Guard is the *mechanism*; the [Repost Policy](#repost-policy) is the *rule* it enforces.

## Amazon Attestation

Amazon's own evidence that a discount is real, read off the [Live Snapshot](#live-snapshot): `dealDetails` (a deal badge + window) **or** a `savingBasis` whose type is `WAS_PRICE` (Amazon's recent actual selling price). Either signal alone counts.

Distinct from a `LIST_PRICE` basis, which is the seller-set MSRP and gameable, so it is **not** attestation. Attestation and our own [Recorded Price History](#recorded-price-history) are the only trustworthy sources of [Discount Magnitude](#discount-magnitude); attestation is rare (~1 in 10 snapshotted items).

## Buy Box

The single winning listing for a product, identified by `isBuyBoxWinner === true` in the [Live Snapshot](#live-snapshot). All price, condition, and merchant facts read from the buy box.

Not the cheapest listing and not `listings[0]`: a product returns multiple listings of varying condition, and the cheapest is often a non-buy-box used item.

## Candidate

A single deal item tracked across its whole life in a [Cycle](#cycle). Its stage is named by adjective:

- **raw candidate** — straight from Keepa's deals endpoint (up to 150 per call), untrusted.
- **surviving candidate** — passed both halves of the [Pre-filter](#pre-filter) and the [Already-Posted Guard](#already-posted-guard); worth spending a [Live Snapshot](#live-snapshot) call on.
- **published candidate** — cleared the [Deal Gate](#deal-gate) and was posted to the [Channel](#channel).

The Live Snapshot and Deal Gate are the (unnamed) transition between *surviving* and *published*.

## Channel

The single WhatsApp channel (`@newsletter`) we publish to, reached through WAHA. Modeled as config so more channels and category-to-channel routing can be added later, but only one exists in this cycle.

## Criteria

The editable, config-driven definition of what deals we *want*: discount percent, price band, sales rank, categories, rating. One half of the [Pre-filter](#pre-filter), applied free on the Keepa payload.

Criteria is taste (what we want), distinct from the [Outlier Guards](#outlier-guards) (what is fake). A raw candidate becomes a surviving candidate only if it matches Criteria **and** passes the Outlier Guards.

## Cycle

One end-to-end run of the [Deal Pipeline](#deal-pipeline): fetch raw [Candidates](#candidate) → [Pre-filter](#pre-filter) → [Already-Posted Guard](#already-posted-guard) → [Live Snapshot](#live-snapshot) → [Deal Gate](#deal-gate) → [publish paced](#pacing) → [record](#recorded-price-history). Driven by cron every X minutes, guarded by a run-lock so cycles cannot overlap, and fail-safe (skip-and-retry on any error).

## Deal Gate

The policy step that reads a candidate's [Live Snapshot](#live-snapshot) and decides publish or skip. Requires [Price Validity](#price-validity) (New, in stock, buy-box winner, merchant check) plus a trustworthy [Discount Magnitude](#discount-magnitude). Its exact volume-vs-trust setting, and the **merchant check** rule (sold-by-Amazon only vs a wider allowlist), are still open product decisions.

Distinct from the [Live Snapshot](#live-snapshot): the Snapshot gathers facts with no verdict, the Gate applies the publish policy to them. Tuning what counts as "a deal" touches only the Gate.

## Deal Pipeline

The headless, scheduled process that runs a [Cycle](#cycle) end to end. The first and only deliverable in this build; later apps (admin UI, landing page, API providers) build on top of it.

## Discount Magnitude

*How big* a price drop is ("% off"). Always measured against a [Reference Price](#reference-price), so it is only as trustworthy as that reference. Trustworthy only via [Amazon Attestation](#amazon-attestation) or our own [Recorded Price History](#recorded-price-history).

The counterpart to [Price Validity](#price-validity): a [Live Snapshot](#live-snapshot) proves validity but **cannot** prove magnitude.

## Gateway

The stateless service (`whatsapp-service`) that delivers a text message to the [Channel](#channel) through [WAHA](#waha), and the only component that holds the WAHA credentials. It is told a channel and a text and sends them, enforcing only that the destination is a `@newsletter` channel; it knows nothing about deals, prices, or [Recorded Price History](#recorded-price-history).

Distinct from the [Deal Pipeline](#deal-pipeline), which decides *what* and *when* to publish: the Gateway is the dumb last mile the Pipeline drives. The Pipeline owns the [Deal Gate](#deal-gate) decision and the record; the Gateway owns delivery only.

## Live Snapshot

The live Creators API call (`GetItems` with `OffersV2`) taken for a surviving [Candidate](#candidate) immediately before publish, and the bundle of facts it returns: live [Buy Box](#buy-box) price, availability, condition, merchant, and any [Amazon Attestation](#amazon-attestation). Carries **no verdict** — it only reports the current truth.

Establishes [Price Validity](#price-validity) only; the [Deal Gate](#deal-gate) reads the Snapshot to decide. The non-negotiable rule: **never publish a price you have not just re-confirmed with a fresh Live Snapshot.**

## Marketplace

A single Amazon storefront (domain, partner tag, currency, locale). Only amazon.de runs now, but it is modeled as configuration so more can be added.

## Operator

The human running the system: defines [Criteria](#criteria), runs the [Deal Pipeline](#deal-pipeline), and receives alerts. Not an end user (a [Channel](#channel) subscriber).

## Outlier Guards

The free checks that reject a raw [Candidate](#candidate) whose price is implausible: spike-polluted baseline, no-demand, price-floor, absurd-claim. One half of the [Pre-filter](#pre-filter), run on the Keepa payload alone.

What they reject is a **Price Outlier** — a fake drop produced by a polluted Keepa [Reference Price](#reference-price), e.g. a long out-of-stock period plus third-party gouging that inflates the average, so a return to the normal price reads as a huge fake discount. The Guards are the *what's fake* half; [Criteria](#criteria) is the *what we want* half.

## Pacing

The rules that limit how fast we publish: a per-cycle cap, randomized spacing between posts, an hourly ceiling, and best-deals-first ordering. Protects subscribers from flooding and keeps the WhatsApp account from looking botty.

## Pre-filter

The umbrella for all free, no-API-call filtering of raw [Candidates](#candidate) before a [Live Snapshot](#live-snapshot). Two halves: [Criteria](#criteria) (what we want) and [Outlier Guards](#outlier-guards) (what is fake). A candidate that clears both, and the [Already-Posted Guard](#already-posted-guard), becomes a surviving candidate — still untrusted on price until the Live Snapshot.

## Price Validity

Whether a price is *real and buyable right now*: live, in stock, New, buy-box, correctly tagged. Established by the [Live Snapshot](#live-snapshot).

The counterpart to [Discount Magnitude](#discount-magnitude): an item can be perfectly valid and still have no trustworthy magnitude.

## Recorded Price History

The prices we ourselves logged at publish time, accumulated across [Cycles](#cycle). Drives the [Already-Posted Guard](#already-posted-guard) and the [Repost Policy](#repost-policy), and over time becomes our most trustworthy [Reference Price](#reference-price) because it is immune to Keepa pollution and seller-set MSRP.

## Reference Price

The price a [Discount Magnitude](#discount-magnitude) is measured against (the "was" price). Every source is reliable to a different degree: a Keepa average is [Price-Outlier](#outlier-guards)-pollutable, an Amazon `LIST_PRICE` is gameable MSRP, an Amazon `WAS_PRICE` / `dealDetails` ([Amazon Attestation](#amazon-attestation)) is trustworthy, and our own [Recorded Price History](#recorded-price-history) is the most trustworthy.

A Reference Price establishes magnitude, never [Price Validity](#price-validity).

## Repost Policy

The rule for when an already-posted ASIN may be posted again. Leading shape: post once, then a cooldown, then re-post only on a meaningful further drop below the last posted price. Enforced by the [Already-Posted Guard](#already-posted-guard).

## WAHA

The third-party Dockerized HTTP bridge that drives a logged-in WhatsApp account through WhatsApp Web, exposing send, session, and channel operations over HTTP. The [Gateway](#gateway) is the only component that talks to it.

Unofficial and the single most fragile dependency: it carries a ban risk and its session can drop, which is why the architecture isolates it behind the [Gateway](#gateway) and keeps the rest of the system fail-safe around it. Not an official WhatsApp API.

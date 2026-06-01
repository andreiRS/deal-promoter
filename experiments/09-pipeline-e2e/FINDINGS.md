# Experiment 09 — full pipeline end to end (Keepa → Creators → HTML)

**Question:** does the whole pipeline run in one automated pass — Keepa discovery →
Creators Live Snapshot + Deal Gate → rendered output — and what does it teach the
PHP/Symfony port? (Canonical terms in [`../../GLOSSARY.md`](../../GLOSSARY.md).)

**Verdict: PASS, with one load-bearing caveat about the Deal Gate (read §1 first).**

One live pass (`bun run 09-pipeline-e2e/run.ts`, amazon.de, 1 page, top-10):

```
150 deals pulled → 17 surviving candidates (free Pre-filter) → top 10 snapshotted → 9 PUBLISH / 1 SKIP
cost: 5 Keepa tokens + 1 Creators transaction
```

The handoff works: Keepa-discovered ASINs flow straight into one `GetItems` call, the Deal Gate
runs on the live buy-box, and the affiliate `detailPageURL` (with `tag=`) renders in the table.
The lone SKIP was correct — a `LEADTIME` (ships in 2–3 days) item, excluded by the in-stock gate.

---

## 1. CRITICAL: the Live Snapshot confirms Price Validity, NOT Discount Magnitude

This is the headline finding and it **refines `docs/specs/product.md`'s open Deal Gate question.**

The Deal Gate we built (the spec's leading option) measures the drop as **live price vs Keepa's
`avg90`**. On this run that Reference Price was persistently polluted and the Deal Gate published
**fake discounts**:

| ASIN | live | Keepa-avg90 drop | Amazon's own signal |
|------|------|------------------|---------------------|
| B00R13CIAY (STARWAX) | 8,70 € | **80% off** | `savingBasis` 9,85 € → **12% off**, `WAS_PRICE`, deal badge present |
| B0D2LQ9VY2 (Tücher) | 9,15 € | **84% off** | **no `savingBasis`, no `savings`, no `dealDetails`** — Amazon flags no deal at all |

Counting Amazon Attestation across all 10 snapshotted items makes the trust problem stark:

| Attestation | count | trustworthy? |
|-------------|-------|--------------|
| `dealDetails` present (real deal badge) | **1** (WAS_PRICE, 12% real) | yes |
| `savings` present but `LIST_PRICE` basis | 3 (claiming 81 / 85 / 88% off MSRP) | **no — gameable MSRP** |
| nothing at all | 6 | n/a |

So "require Amazon `savings`" is NOT a clean fix: 3 of the 4 items with `savings` use the gameable
`LIST_PRICE` and claim 81–88% off, as untrustworthy as Keepa's number. The only genuinely
trustworthy magnitude evidence on the page was `dealDetails` + `WAS_PRICE`: **1 of 10.**

The items are genuinely buyable from Amazon at that price (the Live Snapshot did its job — it
established **Price Validity**), but they are **not 80%+ off** — Keepa's `avg90` is inflated (the
classic long-OOS / third-party-gouging Reference Price that exp04/exp05 flagged as the residual
risk). The Live Snapshot backstops Price Validity — it does **nothing** for **Discount Magnitude**,
because the headline "% off" always rests on a Reference Price, and Keepa's is the polluted one.

**In PHP, do this:** treat Keepa's `avg90` as a *discovery/ranking* Reference Price only, never as
the published discount. For the advertised "% off", trust **Amazon Attestation** from the same
`GetItems` call we already pay for — see §2. Concretely, require corroboration before posting a
big discount: e.g. publish the **conservative** `min(keepaDrop, amazonSavingsPct)`, and require
`dealDetails` present (or `savings` present with `savingBasisType === WAS_PRICE`) before claiming
any headline %. Items with no Amazon deal signal at all (Tücher) should post without a "% off"
claim, or be skipped. This costs **zero** extra calls — it's a re-read of fields already fetched.

> Open question for Andrei: should the Deal Gate **require** Amazon Attestation (`dealDetails` /
> `savings`) to publish, promoting it from the spec's "bonus signal" to a hard gate? That trades
> volume for trust. It does NOT need the deep `/product` stage we dropped — the fix is in the
> Creators fields, so the minimum-steps shape still holds.

---

## 2. `savingBasisType` can be `WAS_PRICE`, not just the `LIST_PRICE` exp08 saw

exp08 concluded `savingBasis` is always the gameable `LIST_PRICE` (MSRP). **Refuted/expanded:**
exp09 saw `savingBasisType: "WAS_PRICE"` (B00R13CIAY) — Amazon's *recent actual* selling price,
a far more trustworthy Reference Price than both `LIST_PRICE` and Keepa's polluted `avg90`.

**In PHP:** read `savingBasisType` and weight it — `WAS_PRICE` is **Amazon Attestation** (trustworthy);
`LIST_PRICE` is not. This is the field that makes §1's fix work.

---

## 3. The funnel shape that survived (and what we removed)

- **Keepa Pre-filter is free and load-bearing.** 0 API calls (runs on the `/deal` payload).
  150 → 17 surviving candidates. Without it, `sortType=4` floats **Price Outliers** to the top and
  the single 10-ASIN Creators transaction is wasted on junk. Keep it. **In PHP:** `DealPreFilter`
  (Criteria + Outlier Guards), pure, `GUARD` bounds as config.
- **Deep `/product?stats` stage stays dropped.** It would not have caught §1 anyway (the Live
  Snapshot can't un-pollute a historical Reference Price), and it cost ~1 token/survivor for a 0/26
  rejection rate in exp05. The §1 fix lives in the Creators response, not in more Keepa calls.
- **Rank on the deal payload alone** (`verifiedDrop × log1p(salesRankDrops90)`) — no second call.
- The `7 surviving candidates dropped by the 10-ASIN cap` line is logged, not silent. **In PHP:**
  carry the overflow to the next cycle (spec's leaning) — the Already-Posted Guard covers it.

---

## 4. Creators transport / shape (confirms + extends exp07/08)

- **One `GetItems` = one transaction** for the whole batch (10 ASINs here). Confirmed.
- **lowerCamelCase envelope** end to end (`itemsResult.items[]`, `offersV2.listings[]`). Confirmed.
- **Reconcile by ASIN, never by position.** All 10 returned, 0 errors this run, but the code maps
  `byAsin[...]` and falls back to `not-accessible` so a missing ASIN can't shift the row mapping.
- **Buy-box selection via `isBuyBoxWinner === true`**, never `listings[0]`/cheapest (exp08). Held.
- **`availability.type` enum observed:** `IN_STOCK`, `IN_STOCK_SCARCE` (both buyable now),
  `LEADTIME` (2–3 day ship — we treat as NOT in stock). **In PHP:** model the enum explicitly;
  decide per-marketplace whether `LEADTIME` is postable.
- **`price.money.amount` is a decimal euro number** (e.g. `8.7`). **In PHP:** convert to integer
  cents at the boundary (`intval(round($amount*100))`) and compare in cents — never compare floats,
  and never compare across the Keepa-cents / Creators-euros boundary without converting.
- **Sold-by-Amazon gate = `merchantInfo.id === "A3JWKAKR8XB7XF"`** (no FBA boolean). Held; all 9
  PUBLISH rows were `name: "Amazon"`. **In PHP:** per-marketplace config value.

---

## 5. Output

`renderHtml()` writes a self-contained, brand-styled `out/results.html` (dark-mode-first,
Inter/JetBrains Mono, semantic colors). This is the throwaway stand-in for the real publish
target (WhatsApp via WAHA) + record step (Postgres) — both out of scope this cycle.

Dumps (gitignored): `out/deal-page-*.dump.json`, `out/creators.dump.json`, `out/results.html`.
A trimmed, tag-scrubbed gated row is committed as `sample.row.json`.

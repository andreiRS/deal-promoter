# Experiment 08 — Creators OffersV2 deep dive (the deal-truth fields)

**Question:** Does the API tell us a product is on a *real* discount? What does
`offersV2.listings.*` actually return — price, was-price, claimed savings, Amazon's
deal flag, condition, availability, merchant — and in what shape/casing?
**Endpoint:** `POST https://creatorsapi.amazon/catalog/v1/getItems` · **Cost:** 1 transaction
**Marketplace:** www.amazon.de (`x-marketplace` header) · **Run date:** 2026-05-31
**ASINs (one call):** DEAL `B08B45VD31` (Sackboy PS5, active Limited-time deal) ·
BASE `B0010AH4BW` (Bosch, exp-05 survivor, no current deal)

## Result: PASS — the discount signals are all there, and the casing is settled

The DEAL item came back with a populated reference price, a claimed savings %, AND
Amazon's own deal flag; the BASE item had none of those. That contrast is the answer.

### The discount answer (DEAL vs BASE, buy-box listing)
| field | DEAL (Sackboy) | BASE (Bosch) |
|---|---|---|
| `price.money` (now) | 24.99 EUR | 1219.27 EUR |
| `price.savingBasis.money` (was) | 69.99 EUR, `savingBasisType: LIST_PRICE` (label "UVP:") | **ABSENT** |
| `price.savings` | 45.00 EUR, `percentage: 64` | **ABSENT** |
| `dealDetails` | **PRESENT** — `badge: "Befristetes Angebot"`, `accessType: ALL`, `startTime`/`endTime` window | **ABSENT** |
| `condition.value` | New | New |
| `availability.type` | IN_STOCK | LEADTIME |
| `merchantInfo` | Amazon (`id A3JWKAKR8XB7XF`) | AUTODOC Shop (`id A214PD1BHHXE2W`) |
| `violatesMAP` | false | false |

**So: yes, the API tells us Amazon considers it a deal** (`dealDetails` present), gives
a reference/was price (`savingBasis`), and a claimed discount (`savings.percentage`).
**But it is not proof of a *real* discount on its own:** `savingBasisType` here is
`LIST_PRICE` (UVP/MSRP), the gameable reference. The genuine-drop decision stays
`price.money` vs **our Keepa-derived baseline**; `savings.percentage` + `dealDetails`
are corroborating signals. Treat absent price/offers as "skip, not a deal" (fail-safe).

### Response shape — CONFIRMED lowerCamelCase end to end (corrects the brief)
The brief documented these from the SDK in PascalCase (`Price.Money`, `SavingBasis`,
`Savings.Percentage`, `DealDetails`). **Live, every level is lowerCamelCase**:
```
items[].offersV2.listings[] = {
  price: { money:{amount,currency,displayAmount},
           savingBasis:{money, savingBasisType, savingBasisTypeLabel},   // only when discounted
           savings:{money, percentage} },                                // only when discounted
  condition: { value, subCondition, conditionNote },
  availability: { type, message, minOrderQuantity, maxOrderQuantity },
  merchantInfo: { id, name },
  dealDetails: { badge, accessType, startTime, endTime },                // only when it's a deal
  isBuyBoxWinner: bool,
  violatesMAP: bool
}
```

### Surprises / corrections to the brief
- **Multiple listings of DIFFERENT conditions come back, and the cheapest is NOT the
  buy box.** Sackboy returned 2 listings: the New buy box @ 24.99 (Amazon) and a Used
  "LikeNew" listing @ 24.49 (Amazon Retourenkauf, `IN_STOCK_SCARCE`). Picking by
  `isBuyBoxWinner === true` is mandatory — `listings[0]`/min-price would pick wrong.
- **"OffersV2 only returns NEW" is REFUTED.** A `Used`/`LikeNew` listing was returned
  (just not as buy box). Gate on `buyBox.condition.value === "New"`, do not assume it.
- **amazon.de "Amazon" seller id = `A3JWKAKR8XB7XF`** (captured here). This closes the
  brief's open item for approximating a "sold by Amazon" gate, since OffersV2 has no
  FBA/sold-by-Amazon boolean. (Note `Amazon Retourenkauf` is a *different* id.)
- **No rate-limit headers** were captured (`logCost` printed no `rate={…}`). The brief's
  "confirm rate headers" item: none observed on the GetItems response.
- `violatesMAP` is present (here `false`) — so the field exists and is checkable.

## Port notes (for the PHP SDK)
- Read the buy-box listing as `item.offersV2.listings[].first(isBuyBoxWinner === true)`;
  never `listings[0]` and never min-price (a Used listing can undercut the New buy box).
- "Real discount" = `price.money` vs our Keepa baseline. `savings.percentage` /
  `savingBasis` / `dealDetails` are Amazon's *claims* (basis is often `LIST_PRICE`),
  good as corroboration and for the post copy, not as the gate.
- Gates: `condition.value === "New"`, `availability.type ∈ {IN_STOCK, IN_STOCK_SCARCE}`,
  `violatesMAP !== true`. For "sold by Amazon", match `merchantInfo.id` against
  `A3JWKAKR8XB7XF` (.de Amazon seller id).
- `dealDetails` presence-only flag with `endTime` is handy for "deal ends in …" copy.

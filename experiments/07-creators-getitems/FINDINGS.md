# Experiment 07 — Creators GetItems (request + response shape)

**Question:** What does `GetItems` actually return for an amazon.de ASIN — the
envelope, the default `detailPageURL` (does it carry our tag?), and where does an
invalid ASIN go?
**Endpoint:** `POST https://creatorsapi.amazon/catalog/v1/getItems` · **Cost:** 1 transaction
**Marketplace:** www.amazon.de (`x-marketplace` header) · **Run date:** 2026-05-31
**ASINs:** GOOD `B0010AH4BW` (exp-05 survivor) · BAD `B000000000` (invalid)

## Result: PASS — full success path confirmed with a real partner tag

Ran the good+bad ASIN pair with a real `something-21` tag. The request shape,
auth, affiliate-link behaviour, and per-item error contract are all now proven
end to end. Committed `sample.item.json` is the verbatim envelope (tag scrubbed to
`YOURTAG-21`). Earlier, a dummy-tag probe had already confirmed host/path/headers/
auth via a clean `InvalidPartnerTag` rejection at the API layer.

### CONFIRMED success path (real tag, ASINs `B0010AH4BW` + `B000000000`)
- `itemsResult.items[]` length **1** (the good ASIN), top-level `errors[]` length
  **1** (the bad ASIN). One transaction billed for the pair.
- `detailPageURL` **auto-carries the affiliate link** (was INFERRED, now proven):
  `https://www.amazon.de/dp/B0010AH4BW?tag=<your-tag>&linkCode=ogi&th=1&psc=1` —
  `tag=` is our partner tag, `linkCode=ogi`. No link-building needed on our side;
  use `detailPageURL` as-is.
- Title comes back as `itemInfo.title.displayValue` (+ `label`, `locale`).

### CONFIRMED request shape (corrected from the brief's PA-API assumptions)
Source: live 400 response + official SDK `thewirecutter/paapi5-php-sdk` v2.x.
| Thing | Brief / first guess | CONFIRMED |
|---|---|---|
| product host | `creatorsapi.amazon.com` / `webservices.amazon.com/paapi5` | **`https://creatorsapi.amazon`** (dotless `.amazon` gTLD, single global host) |
| path | `/paapi5/getitems` | **`/catalog/v1/getItems`** |
| method | POST | **POST** ✓ |
| marketplace | body field `marketplace` | **`x-marketplace` HEADER only**; NOT a body field |
| body keys | lowerCamelCase | **lowerCamelCase**: `partnerTag`, `itemIds`, `resources` (no `itemIdType`, no body `marketplace`) |
| `partnerTag` | required | **required + validated against the credential's store** |
| resource strings | `OffersV2.Listings.Price` (PascalCase) | **lowercase-dotted**: `itemInfo.title`, `offersV2.listings.price`, … |
| response envelope | `ItemsResult.Items[]` / `Errors[]` (PascalCase) | **`itemsResult.items[]` / `errors[]`** (lowerCamelCase); per-item `asin`, `detailPageURL` |
| auth header (v3.x LWA) | `Bearer <token>` | **`Bearer <token>`, no Version suffix** ✓ |

### Error contract (from the dummy-tag probe) — CONFIRMED
A bad/unmapped `partnerTag` returns **HTTP 400**:
```json
{
  "type": "ValidationException",
  "reason": "InvalidPartnerTag",
  "message": "Partner tag in the request is invalid or is not mapped to the store associated with your credential.",
  "fieldList": [{ "name": "partnerTag", "message": "…" }]
}
```
Note this is a **top-level** validation error (whole request rejected), distinct
from the per-item `errors[]` below. Our local `reqEnv` guard catches a
*missing/placeholder* tag before any call; Amazon catches an *invalid* tag here.

### Per-item invalid-ASIN error (real run) — CONFIRMED, with a surprise
An invalid ASIN does **not** fail the request; it lands in the same top-level
`errors[]` array alongside the (separate, request-level) partner-tag error. But
the per-item error entry is leaner than the brief assumed:
```json
{ "code": "InvalidParameterValue",
  "message": "The ItemIds B000000000 provided in the request is invalid." }
```
- Code is **`InvalidParameterValue`**, NOT the inferred `ItemNotAccessible`.
- **There is NO `asin` field on the error entry** — the offending ASIN is only
  in the `message` string. You cannot reconcile errors back to a requested ASIN
  by a structured field; you must either parse the message or diff requested
  ASINs against the returned `items[].asin` set to find which dropped out.
- So `errors[]` is a **flat, untyped bag** mixing request-level and per-item
  problems; treat `code` as the only reliable discriminator.

## Port notes (for the PHP SDK)
- Use `detailPageURL` verbatim as the affiliate link — it already carries our
  `tag=` + `linkCode=ogi`. Do not rebuild links.
- To find which requested ASINs failed, **diff the requested set against
  `itemsResult.items[].asin`** — the `errors[]` entries have no `asin` field, only
  a `code` + a message containing the ASIN. `errors[]` is one flat bag holding
  both request-level (`InvalidPartnerTag`) and per-item (`InvalidParameterValue`)
  problems; branch on `code`.
- The dotless `.amazon` host resolves fine from bun's fetch — no special handling
  needed (our earlier "unable to connect" was the wrong guessed host, not the gTLD).

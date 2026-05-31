# Experiment 06 — Creators API auth + token

**Question:** Which auth path does our credential Version imply, and what does the
OAuth client-credentials token exchange actually return for amazon.de?
**Endpoint:** OAuth `POST /auth/o2/token` (Login with Amazon) · **Cost:** ~0 (rate-limited, not metered)
**Marketplace:** www.amazon.de · **Run date:** 2026-05-31

## Result: PASS

Our credential is **Version 3.2 → the Login with Amazon (LWA) path**, and the
client-credentials exchange returns a working bearer token. The in-memory cache
re-uses it without a second network exchange. The brief's 3.x path description was
correct on every point.

### Confirmed auth path
| Field | Brief says | Live (confirmed) |
|---|---|---|
| Version | 3.2 → LWA | **3.2, LWA** ✓ |
| token endpoint | `api.amazon.co.uk/auth/o2/token` | **same** ✓ |
| scope | `creatorsapi::default` (double colon) | **`creatorsapi::default`** ✓ (echoed back) |
| body format | JSON | **JSON** ✓ |
| `, Version <n>` product-header suffix | omit for 3.x | **omitted** (confirm at exp 07) |
| `expires_in` | ~3600s | **3600** ✓ |
| `token_type` | `Bearer` | **`bearer`** (lowercase) |
| `access_token` shape | — | `Atc|…`, **267 chars** (classic LWA token) |

### Token response (bearer redacted — never commit it)
```json
{
  "access_token": "Atc|…(267 chars, redacted)",
  "scope": "creatorsapi::default",
  "token_type": "bearer",
  "expires_in": 3600
}
```

### Cache check
- Second `getToken()` returned the **same** token, cache populated, no 2nd
  exchange. ✓

## Surprises vs. the brief
1. **Credential page writes the Version with a leading `v`** (`v3.2`). Our first
   `versionMajor()` did `Number("v3.2".split(".")[0])` → **NaN**, which happened to
   pick the right (3.x) path only because `NaN !== 2`. A `v2.2` credential would
   have silently fallen through to the LWA path. **Fixed:** strip non-digits before
   parsing the major, and strip them from the `, Version <n>` suffix too. PHP port
   must do the same — do not assume the Version string is bare digits.
2. `token_type` comes back **lowercase `bearer`** (brief implied `Bearer`).
   Cosmetic, but build the `Authorization` header value ourselves rather than
   echoing `token_type`.

## Port notes (for the PHP SDK)
- The official SDK's `OAuth2TokenManager` does this exchange; we are on the LWA
  (v3.x) variant — JSON body, `creatorsapi::default`, no Version suffix on product
  requests.
- Token cache is in-memory per instance — fine for the CLI cron model (one
  instance per cycle). Only back with PSR-16 (TTL ~3300s) if an HTTP surface later
  calls the API.

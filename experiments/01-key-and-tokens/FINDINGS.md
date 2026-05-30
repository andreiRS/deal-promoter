# Experiment 01 — key validation + live token meter

**Question:** Does the key work? What does the live token meter report?
**Endpoint:** `GET /token` · **Token cost:** 0 (free, confirmed)
**Marketplace:** domainId=3 (amazon.de) · **Run date:** 2026-05-30

## Result: PASS

The key is valid and `/token` returns the meter without spending tokens.

### Raw response
```json
{
  "processingTimeInMs": 0,
  "refillIn": 39592,
  "refillRate": 20,
  "timestamp": 1780141957832,
  "tokenFlowReduction": 0,
  "tokensConsumed": 0,
  "tokensLeft": 1200
}
```

### Meter reading
| Field | Value | Notes |
|---|---|---|
| `tokensLeft` | 1200 | full balance at call time |
| `refillRate` | 20 | tokens/min — this is the 20/min plan |
| `refillIn` | 39592 ms | ms until next refill tick |
| `tokensConsumed` | 0 | confirms `/token` is free |
| `tokenFlowReduction` | 0 | see surprise below |

### Interpretation
- Token cap ≈ `refillRate × 60` = **1200**. The balance is already at cap, so
  `minutesToFull` = 0.
- Rule of thumb holds: ~1200 ASIN deep-looks available now, sustained ~20/min.

## Surprises vs. the brief
1. **`tokenFlowReduction` field** (value 0) is present on the meter but not
   documented in `docs/research/keepa.md`. Likely a throttle/penalty indicator
   that rises when you over-request; worth watching in later probes when we
   request heavier payloads. Should be added to the brief + `TokenMeter`.
2. `processingTimeInMs` is also on the response (0 here) — useful for latency
   logging, not in the brief.

## Port notes (for the PHP client)
- The meter rides on the response body, not headers — read it from JSON.
- Treat 1200 as the working cap for amazon.de on this key; back off when
  `tokensLeft` drops toward the cost of the next intended call.
- Add `tokenFlowReduction` and `processingTimeInMs` to the meter struct.

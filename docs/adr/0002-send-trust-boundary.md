# 2. Trust boundary between pipeline, gateway, and UI

Date: 2026-06-01
Status: Accepted

## Context

The gateway (ADR 0001) exposes both a machine path (the pipeline posts deals) and
a human path (the operator pairs and smoke-tests via a send form). The machine
endpoint triggers a real WhatsApp post and is reachable by anything on the
compose network. The prototype's routes had no auth at all (single-user,
localhost-bound).

Putting both paths on one endpoint forces a choice: either the endpoint is
gated — and then the browser send form must carry the secret, leaking it to the
client — or it is open, and any process on the network can post to the channel.

## Decision

Split by trust level, with one shared in-process client:

- `WahaClient` is the single class that calls WAHA and holds the WAHA `X-Api-Key`.
- `POST /send` (JSON, machine path) is gated by a shared secret `X-Internal-Key`
  that the pipeline also holds. It is the only endpoint the pipeline uses.
- `POST /ui/send` (human path) and the pairing UI (`/`, `/session*`, `/channels`)
  are **open** but published only on a local host port, like the prototype.
- Both `/send` and `/ui/send` route through the same `WahaClient::sendText()`
  in-process — no HTTP hop between them, no duplicated WAHA call.

Neither the WAHA `X-Api-Key` nor the `X-Internal-Key` ever reaches a browser.

## Consequences

- The one dangerous endpoint (real delivery, network-reachable) is gated cheaply,
  mirroring how WAHA itself uses `X-Api-Key`.
- The single-user pairing/test UI stays friction-free, with no login to build
  while the service is host-bound.
- Two routes instead of one, and a second shared secret to configure
  (`WHATSAPP_INTERNAL_KEY`), accepted as the cost of not leaking the WAHA key.
- If the UI is ever exposed beyond localhost, this decision must be revisited —
  the open routes would then need real auth.

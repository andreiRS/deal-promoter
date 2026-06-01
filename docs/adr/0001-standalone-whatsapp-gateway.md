# 1. Standalone WhatsApp gateway service

Date: 2026-06-01
Status: Accepted

## Context

The Deal Pipeline funnels deals to *record* but cannot publish. The
`ChannelPublisher` seam exists with only a `NullChannelPublisher`. Publishing was
validated in a separate TypeScript/Next.js prototype (`whatsapp-announcer`) that
wraps WAHA — a Dockerized, unofficial WhatsApp-Web HTTP bridge that carries a ban
risk and whose session can drop.

Two shapes were on the table:

1. A thin `WahaChannelPublisher` inside the pipeline that talks to WAHA directly —
   no second app. Pairing done by hand via WAHA's own dashboard.
2. A standalone `apps/whatsapp-service` Symfony app that ports the prototype
   (pairing UI + session lifecycle + send), which the pipeline calls over HTTP.

The product spec already anticipates option 2 (commented-out `waha` +
`whatsapp-service` compose stubs, described as "future PHP port of
whatsapp-announcer").

## Decision

Build a standalone `apps/whatsapp-service` Symfony app as a **pure WhatsApp
gateway**, ported 1:1 from the prototype (QR pairing UI, session status/logout,
channel list, manual send form). It is the only component that holds WAHA
credentials and the only one that talks to WAHA. It knows nothing about deals,
prices, or Postgres.

The pipeline remains the brain: a new `WahaChannelPublisher` fills the existing
seam and calls the gateway over HTTP. The gateway is told a channel and a text
and sends them, enforcing only the `@newsletter` channels-only guard.

## Consequences

- The fragile, ban-risky WAHA dependency is isolated behind one service; the rest
  of the system stays fail-safe around it, matching the product constraint.
- The gateway is reusable for non-deal announcements and is independently
  testable (mock WAHA) without the pipeline or a database.
- The pairing UI — the one thing a human still needs — is preserved, and the
  manual send form gives a way to smoke-test WAHA without the pipeline.
- Cost: a second app to build, run, and deploy, plus an internal HTTP hop between
  pipeline and gateway (see ADR 0002 for how that boundary is secured).
- The gateway depends on neither `packages/shared` nor Doctrine, so it does not
  share the pipeline's database — keeping "who wrote what" easy to reason about
  (see ADR 0003 for where delivery is recorded).

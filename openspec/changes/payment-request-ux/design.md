# Design: payment-request-ux

## Context
`OrderPaymentPanel.vue` already implements the payer-facing "pick a PSP, pay now" flow (`school-payments`). The gap
row 13.13/`PA-new-5` identify is narrower than a new feature: (1) nothing records that a request was ever sent, and
(2) the PSP picker is an avoidable extra tap when an install only configures one provider.

## Goals / Non-Goals
**Goals:** record a payment-request-sent timestamp/actor; make the payer's path genuinely one tap when there is
nothing to choose between.

**Non-Goals:** actually pushing/notifying the guardian (D1: portaliq's job); multi-PSP UX; any PSP adapter change.

## Decisions

### Decision 1: `paymentRequestSentAt`/`paymentRequestSentBy` are written through the generic edit form, not a new endpoint
Mirrors `SovereigntyPolicy`/`Compliance`'s established pattern: a schema property that any user already permitted to
update the object can set, via OpenRegister's existing generic object-update endpoint. `Order`'s current
`authorization` (staff read-write cascade, per `school-payments`) already covers this — no RBAC change needed.

### Decision 2: The picker disappears only when `pspOptions.length === 1`, never based on a "requested" flag
Tying the one-tap behaviour to configuration (how many PSPs exist) rather than to `paymentRequestSentAt` keeps the
component's render logic a pure function of what is actually choosable — a payer visiting an order with NO request
recorded, on a single-PSP install, still gets the one-tap path; a payer on a multi-PSP install with a request
recorded still needs to choose, because there is genuinely something to choose. Conflating "was a request sent" with
"is there a choice to make" would be two different questions answered by one flag.

## Declarative-vs-imperative decision (ADR-031)
Both new properties are plain declarative additions; the Vue change is a conditional render with no new derived
state requiring a calculation or lifecycle hook. No imperative exception needed.

## Seed Data (ADR-001)
No new seed objects. Existing `Order` seed objects (if any) gain the two new properties at their default (`null`),
requiring no backfill.

## Risks / Trade-offs
- [Risk] A future multi-PSP-per-region install could want the picker back even at one currently-configured option
  (e.g. testing a rollout) → [Mitigation] configuring a second `pspOptions` entry (even a placeholder) restores the
  picker immediately; no schema flag is needed to opt back in.

## Migration Plan
No Nextcloud migration class — declarative schema update plus a presentational Vue change. Rollback: revert both
files; no data cleanup needed.

## Open Questions
None beyond the one already named in the proposal (a future portaliq read of these fields).

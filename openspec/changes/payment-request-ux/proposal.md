---
kind: config
---

# Proposal: payment-request-ux

## Summary
`findings.md` row 13.13 (Payments) rates Learniq **partial**: `OrderPaymentPanel.vue` already lets a payer pick a PSP and pay, but nine competitors (docebo, talentlms, frappe-education among them) and the Kwieb/Social Schools/Schoolpraat parent-app cohort (`PA-new-5`) all ship a **payment request** — a school pushes a specific request (ouderbijdrage, trip, overblijf) and the guardian pays it in one tap, without hunting for the right order or picking a payment provider every time. Today `OrderPaymentPanel.vue` requires the payer to select a PSP from a dropdown on every visit, and nothing records that a request was ever sent, so staff cannot tell a guardian "I already sent that" or see which requests are still outstanding.

This change adds two additive `Order` properties (`paymentRequestSentAt`, `paymentRequestSentBy`) so staff can mark a request as sent through the existing generic object-edit form (no new endpoint), and makes `OrderPaymentPanel.vue` a genuine one-tap surface: the PSP picker is skipped when only one provider is configured, and the panel shows the guardian when and by whom the request was sent. The PSP adapter itself stays fully delegated to `PaymentInitiationClient.php`/OpenConnector, unchanged.

## Motivation
Row 13.13's competitor evidence is specific about the shape: docebo/talentlms/frappe-education combine an e-commerce checkout with a named fee/invoice record a business already tracks; `PA-new-5` (Kwieb "Betaalverzoeken", Social Schools Betaalmodule, Schoolpraat iDEAL) is explicit that the parent-facing action is a single tap once the request exists. `OrderPaymentPanel.vue`'s own design.md already commits to "one named view for initiating and tracking payment" (`payments` capability spec, "Frontend is declarative" requirement) — this change completes the "tracking" half for the request itself, not just the transaction, and removes the one avoidable extra tap (the PSP picker) when there is nothing to actually choose between.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (two additive `Order` properties), `src/views/OrderPaymentPanel.vue` (skip the PSP picker when one option; show the request-sent note). No other project's files change; `PaymentInitiationClient.php` and `PaymentTransactionController.php` are untouched.

## Scope

### In Scope
- `Order` gains `paymentRequestSentAt` (nullable date-time, default `null`) and `paymentRequestSentBy` (nullable Nextcloud user id string, default `null`). Staff set these through OpenRegister's existing generic object-edit form on the already-declarative `OrderDetail` page — no new controller, per ADR-022 (mirrors `SovereigntyPolicy`/`Compliance`'s own write path).
- `OrderPaymentPanel.vue`: when exactly one PSP option is configured (`pspOptions.length === 1`), the `NcSelect` picker is not rendered and `pspProvider` is pre-set to that one option — a payer with an outstanding request reaches "pay now" in one tap. When more than one option exists, the picker still renders unchanged (no regression for a multi-PSP install).
- `OrderPaymentPanel.vue` also renders a short, non-blocking note when `order.paymentRequestSentAt` is set ("A payment request was sent on {date}"), giving the payer context without adding a second surface.

### Out of Scope
- Actually sending/pushing the request notification to the guardian — D1 keeps all new communication/notification surfaces in portaliq; this change only lets staff RECORD that a request was sent and lets the payer SEE that record. A future portaliq change can read `Order.paymentRequestSentAt`/`paymentRequestSentBy` the same way `portal-contribution-guardian-audiences` reads other learniq fields, to actually notify.
- Any change to `PaymentInitiationClient.php`, `PaymentTransactionController.php`, or the PSP adapter contract — explicitly kept delegated per this change's brief.
- Configuring more than one PSP by default — `pspOptions` already ships `['mollie','stripe']`; the one-tap path only activates once an install is actually configured down to one (a deployment/config decision, not this change's).

## Approach
Two additive schema properties plus a small, purely presentational Vue change (a conditional render and one new computed/text line) — no new endpoint, no new PHP.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json` — two new `Order` properties, both nullable/defaulted, no required-field change.
- `src/views/OrderPaymentPanel.vue` — conditional PSP-picker rendering; one new note line.

## Cross-Project Dependencies
None at build time. Named as the intended future read surface for a portaliq notification change (Out of Scope), not a dependency of this change.

## Risks

### Risk 1: Auto-selecting the sole PSP could surprise an install that expects to always choose
**Severity:** Low — **Mitigation:** the picker only disappears when there is genuinely one option to choose from; the payer still sees which provider will be used is unnecessary information when there is no alternative. An install that wants the picker back simply configures a second `pspOptions` entry.

## Rollback Strategy
Revert `lib/Settings/learniq_register.json` and `src/views/OrderPaymentPanel.vue`. No data migration: any `paymentRequestSentAt`/`paymentRequestSentBy` values already set on live `Order` objects are harmless additive data that a schema revert does not need to clean up.

## Open Questions
- Whether a future portaliq change should surface `paymentRequestSentAt` in the guardian's portal audience (a natural fit for `portal-contribution-guardian-audiences`, but not added there — that change's scope is fixed by this lane's brief).

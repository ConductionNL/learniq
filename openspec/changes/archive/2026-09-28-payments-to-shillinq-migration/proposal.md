---
kind: code
depends_on: []
---

# Proposal: payments-to-shillinq-migration

## Summary

Learniq stops being a payment system. Order, OrderLine and PaymentTransaction leave the register with their pages, menu group, controller, services, guards and listener; the pay screen from #917 goes with them. FeeItem (what a school charges) and Entitlement (what a paid fee grants) stay. An Entitlement is now granted when shillinq reports the payment request on it settled, through a duck-typed signal; without shillinq the grant fails closed. Existing rows are exported to a JSON file first.

## Motivation

Decision D19 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`), closing D12: "school contributions are shillinq invoices paid from portaliq; learniq retires Order, OrderLine and PaymentTransaction in a migration change and keeps FeeItem and Entitlement. payment-request-ux (merged as learniq #917) is unwound by that migration."

The evidence behind D12 and round 2 recon E (`_round2/recon/E-roles-and-lesson-shop.md`, section 1b, row `extracurricular-fee-to-shillinq`, and section 4): shillinq owns invoices, invoice lines, payment requests, SEPA and dunning; portaliq is the shell and shillinq contributes the pay action (`portal-payment-initiation`). Learniq's own stack duplicated that, and its outbound call targets `api/payments/initiate` on integriq, an endpoint that does not exist (the connection registry row said so). Shillinq's `extracurricular-fee-to-shillinq` (#1704) raises a school contribution per guardian with the owning app's chargeable as `subject` and the child as `beneficiary`, and signals settlement with `settledAt`, so learniq can charge through shillinq without either app referencing the other's classes.

## Affected Projects

- [x] Project: `learniq`: register, mock register, manifest, menu layout, routes, registry, guards, listener, repair step, connections, l10n, tests, the `payments` spec.
- [ ] Project: `shillinq`: nothing in this change; see "What shillinq and portaliq still build".
- [ ] Project: `portaliq`: nothing in this change.

## Scope

### In Scope

- Remove the `Order`, `OrderLine`, `PaymentTransaction` schemas (and their mock copies); register 0.26.0.
- `Entitlement` 0.2.0: `orderLineId` goes, `paymentRequestRef`, `paymentSettledAt` and `paymentSettledVia` come; `grant` still requires `FeeItemVoluntaryEntitlementGuard`, which now composes `EntitlementPaymentSettledGuard` instead of `EntitlementOrderPaidGuard`.
- Learniq's side of shillinq's contract extracurricular-fee-to-shillinq v1 (shillinq #1704): `ContributionRaiser` and `POST /api/fee-items/{id}/contributions` raise a FeeItem's contributions for the guardians of its learners; `ShillinqContributionSettledListener` grants the learner's Entitlement on the `settledAt` edge.
- New repair step `ArchiveRetiredPaymentObjects`: every retired row to `payments-archive/retired-payments.json` in learniq's app data folder, before `InitializeSettings`.
- Remove the Orders, OrderLines, PaymentTransactions index and detail pages, the `OrderPaymentPanel` page and view, the `GroupPayments` menu group (FeeItems and Entitlements move under People), `PaymentTransactionController` and its two routes, `PaymentInitiationClient`, `OrderTotalEvaluator`, `OrderTotalValidationGuard`, `EntitlementOrderPaidGuard`, `PaymentTransactionStatusHandler` and their tests; the unarchived `payment-request-ux` change.
- Repoint the `payment` connection row (key kept, keys are frozen) at shillinq, configured by `shillinq_administration_id`; prune 27 catalogue keys only the retired surface used.
- Rewrite `openspec/specs/payments` to the reduced scope through this change's delta.

### Out of Scope

- Converting old orders into shillinq invoices. A paid order is history; an open one is for the school to raise again in shillinq. The archive says so.
- Placing shillinq's `shillinq-payment-requests-panel` leaf on the Entitlement detail page. Learniq has no leaf-placement precedent yet; follow-up.
- Anything in shillinq or portaliq (below).

## What shillinq and portaliq still build

Shillinq built its half in #1704 (merged `2a9d5876`): the raise endpoint and service, invoices and payment requests per guardian, the `settledAt`/`settledVia` signal, and the contract this change builds against. What remains outside learniq:

- **portaliq:** the guardian's pay screen for a school contribution: the object request in the "Pay my invoices" collection shillinq contributes, with `portal-payment-initiation`'s `pay` action working on it (portaliq's own contract consumer is `extracurricular-activity-offer`).
- **shillinq, optional:** a school-fee `kind` beyond `parental-contribution`, `school-trip` and `other` if a school needs one; learniq maps course and contractonderwijs fees to `other` today.
- **operations:** each school sets its shillinq administration (`occ config:app:set learniq shillinq_administration_id`) and gives the administrator shillinq's `payment.request` action.

## Approach

Delete first, then connect: learniq keeps the two objects that describe what a school charges and what a payment unlocks, and learns about payments only from shillinq's own object events, the way ADR-107 and shillinq's `case-payment-requests` intend ("the domain app reacts to the object event, never to a callback into its controller"). Detail and the contract in design.md.

## New Dependencies

None. Shillinq is optional and read duck-typed.

## Impact

The Payments menu disappears; FeeItems and Entitlements appear under People. Two public routes disappear (`/api/payments/{orderId}/initiate`, `/api/payments/callback`). Entitlements need a settled shillinq payment request to activate.

## Cross-Project Dependencies

Reads shillinq's `PaymentRequest` (register `shillinq`, schema `PaymentRequest`, `lib/Settings/register.d/ar-invoice-payment-links.json` on shillinq `development`) when shillinq is installed.

## Risks

### Risk 1: a school with open orders

**Severity:** Medium. **Mitigation:** the archive lists every order with its state; the PR and the archive header say an open order must be raised again in shillinq. No order was ever paid through learniq's own stack in production, because its provider endpoint never existed.

### Risk 2: entitlements that stay pending

**Severity:** Medium. **Mitigation:** intended: an Entitlement opens when its contribution is settled. An administrator raises the contributions from the FeeItem page, and without shillinq the guard refuses with a message that says why.

### Risk 3: a lookalike object granting access

**Severity:** Low. **Mitigation:** the listener only fires `grant`; the guard reads the request back from shillinq's own register by id before it allows the transition.

## Rollback Strategy

Revert the merge commit. The retired rows are still in OpenRegister (only their schema definitions left the register config) and the archive file stays.

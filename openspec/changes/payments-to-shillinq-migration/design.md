# Design: payments-to-shillinq-migration

## What stays, what goes

| learniq object or code | fate |
|---|---|
| `FeeItem` | stays (0.1.1, description only) |
| `Entitlement` | stays, 0.2.0: `orderLineId` out, `paymentRequestRef` and `paymentSettledAt` in |
| `Order`, `OrderLine`, `PaymentTransaction` | schemas removed; rows archived, left in place |
| `FeeItemVoluntaryEntitlementGuard` | stays, composes `EntitlementPaymentSettledGuard` |
| `EntitlementOrderPaidGuard`, `OrderTotalValidationGuard` | removed |
| `PaymentTransactionStatusHandler` | removed; replaced by `ShillinqPaymentSettledListener` |
| `PaymentTransactionController` + 2 routes, `PaymentInitiationClient`, `OrderTotalEvaluator` | removed |
| `OrderPaymentPanel.vue` + page, Orders / OrderLines / PaymentTransactions pages, `GroupPayments` | removed; FeeItems and Entitlements relocated under People |
| `payment-request-ux` (#917): Order.paymentRequestSentAt/By, panel changes, its register test | gone with Order and the panel; its unarchived change directory removed |
| `payment` connection row | key kept (keys are frozen), repointed at shillinq, still unavailable |

## The shillinq contract

Read from shillinq `development` (`7781d5fb`): `lib/Settings/register.d/ar-invoice-payment-links.json` (PaymentRequest 0.3.0), `lib/Service/PaymentReconciliationService.php`, `openspec/changes/case-payment-requests/`.

**The request.** A shillinq `PaymentRequest` in register `shillinq`, schema `PaymentRequest`, with:

| field | value for a learniq fee |
|---|---|
| `subjectKind` | `object` |
| `subject` | `{type: "entitlement", register: "learniq", schema: "entitlement", id: <Entitlement uuid>}` (ADR-048 semantic reference) |
| `requestType` | `other` until shillinq adds a school-fee type |
| `amount`, `currency`, `description` | from the Entitlement's FeeItem |
| `debtor` | the guardian or employer who pays |

**The settled signal.** Shillinq's reconciliation writes `state` with a plain `saveObject()` (`PaymentReconciliationService::reconcile()`), so OpenRegister dispatches `ObjectUpdatedEvent`. Learniq listens to that event and recognises the request by shape, never by class: `paymentGateway` present, `subjectKind: object`, `subject.register: learniq`, `subject.schema: entitlement`.

| state change | learniq does |
|---|---|
| anything → `captured` | stamp `paymentRequestRef` (the request uuid) and `paymentSettledAt` on the pending Entitlement, fire `grant` |
| `captured` → `voided` | fire `revoke` on the active Entitlement whose `paymentRequestRef` is this request |
| anything else (`captured_unapplied`, `failed`, `expired`, a re-save of `captured`) | nothing |

`captured_unapplied` is not settled: shillinq took the money but could not book the receipt (REQ-APL-005).

**The guard.** `EntitlementPaymentSettledGuard` allows `grant` only when shillinq is installed (`IAppManager::isInstalled('shillinq')`), `paymentRequestRef` is set, `ObjectService::find()` finds that id in register `shillinq`, schema `PaymentRequest` (no RBAC, no multitenancy), its `subject` names this Entitlement, and its `state` is `captured`. Every other case refuses. The listener fires `grant` and the guard decides, so a lookalike object in another register grants nothing.

**Why an event, not a webhook.** `case-payment-requests` says a domain app "reacts to the object event, never to a callback into its controller", and ADR-107 keeps booking in shillinq. A webhook would need a public learniq route, a shared secret and a retry story; the object event needs none of those and is what shillinq already emits.

## Archive

`ArchiveRetiredPaymentObjects` reads `order`, `order-line`, `payment-transaction` (paged, no RBAC) and writes one file, `payments-archive/retired-payments.json`, in learniq's app data folder: `exportedAt`, a `reason` naming D19 and saying open orders must be raised again in shillinq, `counts` and `objects` per schema. It runs before `InitializeSettings` in `post-migration`, skips when the file exists, and writes nothing when there are no rows.

## Declarative-vs-imperative decision

| behaviour | path | reason |
|---|---|---|
| Entitlement lifecycle | declarative (`x-openregister-lifecycle`) | unchanged |
| grant guard | imperative lifecycle guard | ADR-031 exception: a rule over another app's object |
| settled signal | imperative listener | ADR-031 exception: a cross-app write no schema expression can make |
| archive | imperative repair step | one-time data export |

## Seed Data

No seeds existed for the retired schemas or for Entitlement and FeeItem in the mock register; none are added.

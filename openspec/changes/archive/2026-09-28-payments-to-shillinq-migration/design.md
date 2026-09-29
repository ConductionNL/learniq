# Design: payments-to-shillinq-migration

## What stays, what goes

| learniq object or code | fate |
|---|---|
| `FeeItem` | stays (0.1.1, description only) |
| `Entitlement` | stays, 0.2.0: `orderLineId` out, `paymentRequestRef`, `paymentSettledAt` and `paymentSettledVia` in |
| `Order`, `OrderLine`, `PaymentTransaction` | schemas removed; rows archived, left in place |
| `FeeItemVoluntaryEntitlementGuard` | stays, composes `EntitlementPaymentSettledGuard` |
| `EntitlementOrderPaidGuard`, `OrderTotalValidationGuard` | removed |
| `PaymentTransactionStatusHandler` | removed; replaced by `ShillinqContributionSettledListener` |
| `PaymentTransactionController` + 2 routes, `PaymentInitiationClient`, `OrderTotalEvaluator` | removed |
| `OrderPaymentPanel.vue` + page, Orders / OrderLines / PaymentTransactions pages, `GroupPayments` | removed; FeeItems and Entitlements relocated under People |
| `payment-request-ux` (#917): Order.paymentRequestSentAt/By, panel changes, its register test | gone with Order and the panel; its unarchived change directory removed |
| `payment` connection row | key kept (keys are frozen), repointed at shillinq, still unavailable |

## The shillinq contract

Shillinq owns it: `openspec/changes/extracurricular-fee-to-shillinq/contract.md`, version 1, merged with shillinq #1704 (`2a9d5876`). Learniq is a named consumer. Everything below is learniq's side of it, duck-typed: no `use` of a shillinq class, no `info.xml` dependency.

### Raising a fee's contributions

`ContributionController::raise()` (`POST /apps/learniq/api/fee-items/{id}/contributions`, action `fee-item.raise-contributions`, admin by default) hands an active FeeItem to `ContributionRaiser`, which calls `OCA\Shillinq\Service\ContributionRaiseService::raise()` in process through `ShillinqContributionClient` (the contract's in-process form of `POST /apps/shillinq/api/contributions/raise`; same array in, same array out). Shillinq checks `payment.request` on the same session user; its 403 is reported as 403, its `InvalidArgumentException` as 400.

| contract field | learniq sends |
|---|---|
| `chargeable` | `{app: learniq, type: fee-item, register: learniq, schema: fee-item, id: <FeeItem uuid>}` |
| `kind` | `schoolkassa` → `parental-contribution`, `school-trip` → `school-trip`, every other kind → `other` |
| `description`, `amount`, `currency`, `voluntary` | from the FeeItem |
| `administrationId` | the request's `administrationId`, else app config `shillinq_administration_id` (the connection row asks for it) |
| `invoiceDate`, `dueDate`, `revenueAccount` | passed through when the caller gives them |
| `recipients[]` | one per learner: `debtor` the first guardian (`LearnerProfile.parentIds`) with an e-mail address, or the learner when the profile names no guardian; `beneficiary` `{type: learner, register: learniq, schema: learner-profile, id: <profile uuid>}` |

The learners are the FeeItem's group (`linkedCohortId` → `Cohort.learnerIds`) or the pending and active enrolments of its course (`linkedCourseId`). A learner nobody can be mailed about is reported as `not-sent`. Recipients go in chunks of 200 (the contract's cap) and each result is mapped back to its learner. A second raise is safe: shillinq answers `skipped` with the request that already stands.

For a non-voluntary FeeItem whose kind unlocks something (course, contractonderwijs, trip, materials), each learner gets a pending Entitlement if they have none, with `paymentRequestRef` set from the result. A voluntary FeeItem creates no Entitlement.

The contract's example spells the schema `FeeItem`; learniq sends its slug `fee-item` and accepts either when it reads a request back.

### The settled signal

Shillinq stamps `settledAt` and `settledVia` on the PaymentRequest once, the first time it counts as paid (provider, cash, pin, bank transfer, waiver), and never clears them. `ShillinqContributionSettledListener` is subscribed from `boot()` on `ObjectUpdatedEvent`, narrowed to register `shillinq`, schema `PaymentRequest` (`BootListenerRegistrar::registerPaymentListeners()`), and keys on the edge exactly as the contract says: old object without `settledAt`, new object with it, `subject.app` learniq. It then finds the learner's pending Entitlements for `subject.id` (the FeeItem) and the beneficiary, stamps `paymentRequestRef`, `paymentSettledAt`, `paymentSettledVia`, and fires `grant`. A beneficiary is read in either contract shape (a LearnerProfile reference, resolved to its `ncUserId`, or a bare user id).

### The guard

`EntitlementPaymentSettledGuard` allows `grant` only when shillinq is installed, `paymentRequestRef` names a PaymentRequest in shillinq's register (read without RBAC), its `settledAt` is set, its `subject` is this Entitlement's FeeItem and its `beneficiary` this Entitlement's learner. Everything else refuses, so the guard fails closed without shillinq, and a lookalike object in another register grants nothing.

### What is not in the contract

A refund or a voided request after settlement produces no signal (`settledAt` is never cleared). Revoking an Entitlement stays a person's action. Integriq's outbound webhook form of the signal is for systems outside Nextcloud; learniq uses the in-process event.

## Archive

`ArchiveRetiredPaymentObjects` reads `order`, `order-line`, `payment-transaction` (paged, no RBAC) and writes one file, `payments-archive/retired-payments.json`, in learniq's app data folder: `exportedAt`, a `reason` naming D19 and saying open orders must be raised again in shillinq, `counts` and `objects` per schema. It runs before `InitializeSettings` in `post-migration`, skips when the file exists, and writes nothing when there are no rows.

## Declarative-vs-imperative decision

| behaviour | path | reason |
|---|---|---|
| Entitlement lifecycle | declarative (`x-openregister-lifecycle`) | unchanged |
| grant guard | imperative lifecycle guard | ADR-031 exception: a rule over another app's object |
| settled signal | imperative listener | ADR-031 exception: a cross-app write no schema expression can make |
| raising contributions | imperative service and controller | a call into another app's service, with recipients derived from three schemas |
| archive | imperative repair step | one-time data export |

## Seed Data

No seeds existed for the retired schemas or for Entitlement and FeeItem in the mock register; none are added.

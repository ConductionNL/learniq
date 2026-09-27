# Tasks: payments-to-shillinq-migration

Tier: must (MVP). D19.

## 1. Register

- [x] 1.1 Remove `Order`, `OrderLine`, `PaymentTransaction` from `learniq_register.json`, its schema list and the mock copy; register 0.26.0.
- [x] 1.2 `Entitlement` 0.2.0: drop `orderLineId`, add `paymentRequestRef` and `paymentSettledAt`; update its description and lifecycle comment. `FeeItem` 0.1.1 description.

## 2. Payment state from shillinq

- [x] 2.1 New `EntitlementPaymentSettledGuard` (contract in design.md); `FeeItemVoluntaryEntitlementGuard` composes it.
- [x] 2.2 New `ShillinqPaymentSettledListener` on `ObjectUpdatedEvent`, registered in `SchedulingListenerRegistrar` in place of `PaymentTransactionStatusHandler`.

## 3. Archive

- [x] 3.1 New `ArchiveRetiredPaymentObjects`, in `post-migration` before `InitializeSettings`; app version bumped.

## 4. Removals

- [x] 4.1 Remove `PaymentTransactionController` and its routes, `PaymentInitiationClient`, `OrderTotalEvaluator`, `OrderTotalValidationGuard`, `EntitlementOrderPaidGuard`, `PaymentTransactionStatusHandler` and their tests.
- [x] 4.2 Remove the Orders, OrderLines, PaymentTransactions pages, `OrderPaymentPanel` (page, view, registry entry, e2e spec) and `GroupPayments`; relocate FeeItems and Entitlements under People.
- [x] 4.3 Remove the unarchived `payment-request-ux` change (its code is gone with Order and the panel).
- [x] 4.4 Repoint the `payment` connection row at shillinq (key kept) and its test.

## 5. Copy

- [x] 5.1 Prune catalogue keys only the retired surface used; add English and Dutch keys for the new strings; `npm run l10n:build`; lower the schema l10n baseline.

## 6. Tests

- [x] 6.1 `EntitlementPaymentSettledGuardTest`, `ShillinqPaymentSettledListenerTest`, `ArchiveRetiredPaymentObjectsTest`, `PaymentsToShillinqRegisterTest`; `FeeItemVoluntaryEntitlementGuardTest` rewritten; retired schemas dropped from the register ratchet lists.

## 7. Verify and ship

- [x] 7.1 Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run check:specs`, hydra gates, once.
- [x] 7.2 PR body names what shillinq and portaliq still build.

Documentation: the People menu gains two entries and the Payments menu goes; no screenshot run (no instance for this lane).

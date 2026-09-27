## Implementation Tasks

### Task 1: Add `paymentRequestSentAt`/`paymentRequestSentBy` to `Order`
- **spec_ref**: `openspec/changes/payment-request-ux/specs/payments/spec.md#requirement-a-staff-recorded-payment-request-lets-a-payer-reach-checkout-in-one-tap`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `Order.properties` THEN `paymentRequestSentAt` (date-time, nullable, default null) and `paymentRequestSentBy` (string, nullable, default null) exist, both with title+description
  - GIVEN `Order.required` THEN neither new property is added to it
- [x] Implement
- [x] Test

### Task 2: One-tap PSP picker and request-sent note in `OrderPaymentPanel.vue`
- **spec_ref**: `openspec/changes/payment-request-ux/specs/payments/spec.md#requirement-a-staff-recorded-payment-request-lets-a-payer-reach-checkout-in-one-tap`
- **files**: `src/views/OrderPaymentPanel.vue`
- **acceptance_criteria**:
  - GIVEN `pspOptions.length === 1` THEN the `NcSelect` picker is not rendered and `pspProvider` is pre-set to that option's value
  - GIVEN `pspOptions.length > 1` THEN the picker renders exactly as before this change
  - GIVEN `order.paymentRequestSentAt` is set THEN a note naming the date is rendered
- [x] Implement
- [x] Test

### Task 3: Register-shape test for the two new properties
- **spec_ref**: `openspec/changes/payment-request-ux/specs/payments/spec.md`
- **files**: `tests/Unit/Settings/OrderPaymentRequestRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN it asserts both new properties' type/nullable/default and that neither is required
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate payment-request-ux --strict` passes
- [x] Manual review against acceptance criteria (no Vue component-test infrastructure exists in this app to automate the render-logic scenarios — named gap, not claimed elsewhere)

## Tests (company-wide ADR-009)
- [x] PHPUnit unit test for the register delta (`tests/Unit/Settings/OrderPaymentRequestRegisterTest.php`)
- [x] N/A — no new API endpoint; no Vue component-test infrastructure exists in this app (verified by grep) to add a Playwright/vitest case against, so the render-logic scenarios are verified by code review only — named explicitly rather than claimed covered

## Documentation (company-wide ADR-010)
- [x] N/A — presentational change to an existing payer-facing panel; no new user-facing concept requiring a docs update

## i18n (company-wide ADR-005)
- [x] New user-facing string (the request-sent note) added via `t()`; Dutch string added to `l10n/nl.json`

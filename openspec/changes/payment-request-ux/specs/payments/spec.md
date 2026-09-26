## ADDED Requirements

### Requirement: A staff-recorded payment request lets a payer reach checkout in one tap
`Order` SHALL gain `paymentRequestSentAt` (nullable date-time, default `null`) and `paymentRequestSentBy` (nullable
Nextcloud user id, default `null`), settable through OpenRegister's existing generic object-edit endpoint (no new
controller). `OrderPaymentPanel.vue` SHALL skip rendering its PSP picker when exactly one PSP option is configured,
pre-selecting that option so the payer reaches "pay now" in a single tap, and SHALL render a note when
`paymentRequestSentAt` is set, naming when the request was sent. When more than one PSP option is configured the
picker SHALL render exactly as before this change.

#### Scenario: A payer with a single configured PSP pays in one tap
- **GIVEN** an `Order` in `open` state and exactly one `pspOptions` entry configured
- **WHEN** a guardian opens `OrderPaymentPanel` for that order
- **THEN** no PSP picker is shown
- **AND** clicking "Pay now" calls `PaymentTransactionController::initiate()` with that one PSP, unchanged from the
  existing initiation flow

#### Scenario: A payer sees when a request was sent
- **GIVEN** an `Order` with `paymentRequestSentAt` set
- **WHEN** the payer opens `OrderPaymentPanel`
- **THEN** they see a note naming when the request was sent

#### Scenario: Multiple configured PSPs still show the picker
- **GIVEN** an install with two `pspOptions` entries
- **WHEN** a payer opens `OrderPaymentPanel`
- **THEN** the PSP picker renders exactly as before this change

<!-- @e2e exclude No Vue component-test infrastructure (vitest + @vue/test-utils) exists anywhere in this app yet to mirror — verified by grep, not assumed; this is a genuine, named gap, not a claimed-elsewhere coverage. The conditional-render logic is verified by code review against this scenario and the register-shape test (OrderPaymentRequestRegisterTest) for the two new properties; no PHP path changes. -->


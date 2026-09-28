## Implementation Tasks

### Task 1: Add the teldatum-check properties to `DataExchangeJob` and extend the run guard
- **spec_ref**: `openspec/changes/funding-and-teldatum-checks/specs/data-exchange/spec.md#requirement-a-dataexchangejob-target-can-require-a-confirmed-teldatum-pre-flight-check-before-it-runs`
- **files**: `lib/Settings/learniq_register.json`, `lib/Lifecycle/DataExchangeRunGuard.php`, `tests/Unit/Lifecycle/DataExchangeRunGuardTest.php`
- **acceptance_criteria**:
  - GIVEN `DataExchangeJob` THEN it gains `requiresTeldatumCheck` (default false), `teldatumCheckStatus` (enum not-required/pending/confirmed, default not-required), `teldatumCheckDate`, `teldatumCheckedBy`, `teldatumCheckedAt` (nullable, default null)
  - GIVEN `DataExchangeRunGuard::check()` WHEN `requiresTeldatumCheck === true` AND `teldatumCheckStatus !== 'confirmed'` THEN it returns false regardless of target
  - GIVEN the same guard WHEN `requiresTeldatumCheck === false` (default) THEN existing OSO/SWV/other-target behaviour is byte-for-byte unchanged
- [x] Implement
- [x] Test

### Task 2: Add `fundingWeightCode` to `LearnerProfile`
- **spec_ref**: `openspec/changes/funding-and-teldatum-checks/specs/enrolment/spec.md#requirement-learnerprofile-records-the-noatcuminnca-funding-weight-classification`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `LearnerProfile.properties.fundingWeightCode` THEN it is a nullable enum `["noat","cumi","nnca"]`, default null, with title+description
  - GIVEN `LearnerProfile.required` THEN `fundingWeightCode` is not added to it
- [x] Implement
- [x] Test

### Task 3: Register-shape and guard tests
- **spec_ref**: `openspec/changes/funding-and-teldatum-checks/specs/data-exchange/spec.md`
- **files**: `tests/Unit/Settings/FundingTeldatumRegisterTest.php`, `tests/Unit/Lifecycle/DataExchangeRunGuardTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN it asserts the exact shape of all six new properties across the two schemas
  - GIVEN the guard WHEN a job requires a teldatum check and is not confirmed THEN `check()` returns false; WHEN confirmed THEN true; WHEN not required THEN existing behaviour unchanged
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate funding-and-teldatum-checks --strict` passes
- [x] Manual review against acceptance criteria

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests (`tests/Unit/Settings/FundingTeldatumRegisterTest.php`, extended `DataExchangeRunGuardTest.php`)
- [x] N/A — no new API endpoint, no UI change

## Documentation (company-wide ADR-010)
- [x] N/A — declarative additions to existing generic pages; no new user-facing concept requiring a docs update

## i18n (company-wide ADR-005)
- [x] N/A — no new user-facing strings (properties surface through the generic object-edit form's existing i18n)

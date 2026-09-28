# Tasks: round1-landing-repairs

Stacked on `access-control-ratchet-compliance`. Acceptance: the full PHPUnit suite shows zero failures and phpmd zero findings.

## Implementation Tasks

### Task 1: One recipient entry on thresholdCrossed
- **spec_ref**: `openspec/changes/round1-landing-repairs/specs/attendance/spec.md#requirement-a-crossed-threshold-notifies-each-recipient-group-once`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Settings/AttendanceThresholdRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN thresholdCrossed is read THEN it has one entry, mentor and coordinator, and no group twice
- [x] Implement
- [x] Test

### Task 2: Connection descriptions and catalogue keys
- **spec_ref**: `openspec/changes/round1-landing-repairs/specs/data-exchange/spec.md#requirement-the-connection-field-names-every-connection-learniq-hands-to-openconnector`
- **files**: `lib/Settings/learniq_register.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- **acceptance_criteria**:
  - GIVEN both target descriptions WHEN read THEN every connection is named and both have en and nl keys
- [x] Implement
- [x] Test

### Task 3: Seed tests assert floors and find rows by id
- **spec_ref**: `openspec/changes/round1-landing-repairs/specs/data-exchange/spec.md#requirement-register-tests-find-seed-rows-by-identity-and-assert-floors`
- **files**: `tests/Unit/Settings/DataMappingProfilePresetsRegisterTest.php`, `UwlrEduvBasispoortRegisterTest.php`, `ConfidentialCounsellorChannelRegisterTest.php`, `CourseShareConsentRegisterTest.php`, `SharedCoursePackageRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN 23 mapping presets WHEN the suite runs THEN the count tests pass as floors
- [x] Implement
- [x] Test

### Task 4: Transition bridge registrar
- **spec_ref**: `openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change`
- **files**: `lib/AppInfo/Registrar/TransitionBridgeListenerRegistrar.php`, `CaseListenerRegistrar.php`, `SchedulingListenerRegistrar.php`, `EventListenerWiring.php`
- **acceptance_criteria**:
  - GIVEN the wiring WHEN registered THEN both bridges are registered once and phpmd reports no coupling finding
- [x] Implement
- [x] Test

### Task 5: Versions, full suite, PR, close #1006
- **spec_ref**: `openspec/changes/round1-landing-repairs/specs/data-exchange/spec.md#requirement-the-connection-field-names-every-connection-learniq-hands-to-openconnector`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the branch WHEN check:strict runs THEN PHPUnit shows zero failures
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit covers every repair
- No API, no screen: no Newman, no Playwright
- Documentation: not applicable
- i18n: en and nl keys for the two new descriptions
- `openspec validate` passes

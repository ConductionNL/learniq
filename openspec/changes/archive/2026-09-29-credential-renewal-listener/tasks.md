# Tasks: credential-renewal-listener

## Implementation Tasks

### Task 1: Add `credential-renewal` to Enrolment.source's enum
- **spec_ref**: `openspec/changes/credential-renewal-listener/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `Enrolment.properties.source.enum` WHEN read THEN it contains
    `credential-renewal`
- [x] Implement
- [x] Test

### Task 2: Add CredentialRenewalListener
- **spec_ref**: `openspec/changes/credential-renewal-listener/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change`
- **files**: `lib/Listener/CredentialRenewalListener.php`
- **acceptance_criteria**:
  - GIVEN a Credential transitioning `issued` -> `expired` WHEN the `expire`
    transition fires THEN a new Enrolment is created with the same
    learnerId/courseId, `source: credential-renewal`, `mandatory: true`
  - GIVEN the created Enrolment WHEN saved THEN its id is written back onto
    the Credential's `renewalEnrolmentId`
  - GIVEN a Credential missing learnerId or courseId WHEN `expire` fires
    THEN no Enrolment is created and no exception is raised
- [x] Implement
- [x] Test

### Task 3: Register the listener
- **spec_ref**: `openspec/changes/credential-renewal-listener/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change`
- **files**: `lib/AppInfo/Registrar/SchedulingListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN listeners are registered THEN
    `CredentialRenewalListener` is registered for `ObjectTransitionedEvent`
- [x] Implement
- [x] Test

### Task 4: Unit tests with `createMock()` doubles
- **spec_ref**: `openspec/changes/credential-renewal-listener/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change`
- **files**: `tests/Unit/Listener/CredentialRenewalListenerTest.php`
- **acceptance_criteria**:
  - Every scenario in the spec has a corresponding test method
  - Doubles use `createMock()` only — never `addMethods()`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  `CredentialRenewalListenerTest`
- [x] Newman/Postman tests for new/changed API endpoints — N/A, no API changed
  - N/A, confirmed in r5-structure: f825c3f1 (#918) touches no controller and no `appinfo/routes.php`.
- [x] Browser tests (Playwright MCP) for UI changes — N/A, no UI changed
  - N/A, confirmed in r5-structure: f825c3f1 changes nothing under `src/`.
- [x] All tests pass (`vendor/bin/phpunit --filter CredentialRenewalListenerTest`)

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` — N/A, no new user-facing surface
  - N/A, confirmed in r5-structure: a backend listener with no page or setting.
- [x] Screenshot captured and committed to `docs/images/` — N/A, no visual change
  - N/A, confirmed in r5-structure: nothing visual changed.

## i18n (company-wide ADR-005)
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A,
  no new user-facing strings
  - Not N/A after all: f825c3f1 rewrote the `Enrolment.source` description, which renders as helper text. r5-structure added its title and description to `l10n/en.json` and `l10n/nl.json` (both were uncovered), listed the Dutch values in `l10n/ai-translated.json` for review, and lowered the `check:schema-l10n` baseline by two.

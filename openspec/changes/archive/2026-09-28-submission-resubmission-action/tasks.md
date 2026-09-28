# Tasks: submission-resubmission-action

## Implementation Tasks

### Task 1: Extend the Submission schema and the reopen transition
- **spec_ref**: `openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-a-teacher-can-ask-for-returned-work-to-be-handed-in-again`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `tests/Unit/Settings/SubmissionResubmissionRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN Submission has `resubmissionDueAt` (date-time, nullable) and `reopen` declares it as a required input with staff-only authorization
  - GIVEN the register WHEN read THEN `resubmissionRequested` notifies `learnerIds` on `reopen`
  - Submission `version` and `info.version` are bumped; one seed draft Submission carries a date
- [x] Implement
- [x] Test

### Task 2: Honour the resubmission date in SubmissionWindowGuard
- **spec_ref**: `openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline`
- **files**: `lib/Lifecycle/SubmissionWindowGuard.php`, `tests/Unit/Lifecycle/SubmissionWindowGuardTest.php`
- **acceptance_criteria**:
  - GIVEN a passed assignment deadline and a future resubmission date WHEN submit THEN allowed
  - GIVEN a passed resubmission date on a late-accepting assignment WHEN submit THEN refused, and submitLate allowed
  - GIVEN a future resubmission date WHEN submitLate THEN refused
- [x] Implement
- [x] Test

### Task 3: Only staff write the resubmission date
- **spec_ref**: `openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-only-staff-set-a-resubmission-date`
- **files**: `lib/Listener/SubmissionResubmissionDateListener.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `tests/Unit/Listener/SubmissionResubmissionDateListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a learner update with a later date WHEN written THEN the stored date is kept
  - GIVEN a learner create with a date WHEN written THEN it is dropped
  - GIVEN a teacher, an admin or system context WHEN written THEN the date is stored
- [x] Implement
- [x] Test

### Task 4: Add the button to SubmissionDetail
- **spec_ref**: `openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-a-teacher-can-ask-for-returned-work-to-be-handed-in-again`
- **files**: `src/manifest.d/learning.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a returned Submission WHEN SubmissionDetail renders THEN one action, "Ask to hand in again", fires `reopen` with a `resubmissionDueAt` input
  - Every new string has an English and a Dutch catalogue value
- [x] Implement
- [x] Test

## Quality checklist

- `vendor/bin/phpunit --filter 'SubmissionWindowGuardTest|SubmissionResubmission'` and `tests/Unit/Register/`
- `npm run check:manifest`, `npm run check:schema-l10n`
- `openspec validate submission-resubmission-action` passes

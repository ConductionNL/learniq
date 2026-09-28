# Tasks: oso-import-dossier-nullable-job

## Implementation Tasks

### Task 1: Declare the job reference nullable
- **spec_ref**: `openspec/changes/oso-import-dossier-nullable-job/specs/data-exchange/spec.md#requirement-a-dossier-received-without-an-exchange-job-is-valid`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `tests/Unit/Register/DemoNullsAreNullableTest.php`, `l10n/en.json`, `l10n/nl.json`
- [x] Test written first and red
- [x] Implement

## Verification
- [x] `openspec validate oso-import-dossier-nullable-job` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

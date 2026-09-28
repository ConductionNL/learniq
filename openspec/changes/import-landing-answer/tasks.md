# Tasks: import-landing-answer

## Implementation Tasks

### Task 1: Land received import records and answer
- **spec_ref**: `openspec/changes/import-landing-answer/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq`
- **files**: `lib/Listener/ExchangeImportLandingListener.php`, `lib/Service/ExchangeImportLanding.php`, `lib/AppInfo/Registrar/CaseListenerRegistrar.php`, `tests/Stubs/Integriq/Event/ExchangeRecordsReceivedEvent.php`, `tests/Unit/Listener/ExchangeImportLandingListenerTest.php`
- [x] Test against the verbatim event written first and red
- [x] Implement

## Verification
- [x] `openspec validate import-landing-answer` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

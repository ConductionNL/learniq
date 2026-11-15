# Tasks: import-records-in-gate-answer

## Implementation Tasks

### Task 1: Read an import job's file into records and put them in the gate answer
- **spec_ref**: `openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq`
- **files**: `lib/Service/ExchangeImportInput.php`, `lib/Service/ExchangeGateService.php`, `tests/Unit/Service/ExchangeImportInputTest.php`, `tests/Unit/Service/ExchangeGateServiceTest.php`
- [x] Tests written first and red
- [x] Implement

## Verification
- [x] `openspec validate import-records-in-gate-answer` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body
  - Done on the originating PR #1229: `composer check:strict` 0 (2177 tests OK, phpmd 0 after an isolated-HOME rerun), `npm run lint` 0, hydra gates exit 8 with only inherited reds (gates 3, 25, 49, 53, 55, 60, 112, 113, none on its files). Re-run on current development in the r5-structure part 2 PR.

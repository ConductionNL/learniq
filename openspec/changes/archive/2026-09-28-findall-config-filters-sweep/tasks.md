# Tasks: findall-config-filters-sweep

Tier: must (MVP). Every read in learniq depends on it.

## 1. Confirm the contract

- [x] 1.1 Read OpenRegister `ObjectService::prepareFindAllConfig()` on `development` and record the file and line in proposal.md and design.md.
- [x] 1.2 Measure the blast radius on `origin/development` before writing: 170 literal configs and 3 variable-built configs, all on `$this->objectService`.

## 2. Rewrite the calls

- [x] 2.1 Scripted rewrite of the 167 sites the scanner could handle, written to a scratch copy first and linted there.
- [x] 2.2 Hand edits for the six sites the script refused: `XapiCompletionHandler` x2 (`tenantScoped()` expressions), `AssessmentResultAudience` (single-line config), `ComplianceRollupService::rows()`, `RegulationAssignmentService::rows()`, `LearniqToolProvider::buildCourseListConfig()`.
- [x] 2.3 Duplicate-key scan; fix the four configs with two `filters` keys in `AssessmentGradeGuard` and `AssessmentScoringHandler` by moving the scope into the `tenantScoped()` filters.

## 3. Tests

- [x] 3.1 Add `tests/Unit/FindAllConfigScopeTest.php`: tokenises `lib/`, fails on a top-level `register`/`schema` key (inline or through a variable) and on a duplicate key, asserts it inspected at least 150 calls, with control tests for each defect and for the correct shape.
- [x] 3.2 Prove the new test fails on `origin/development` (346 findings) and passes on the branch.
- [x] 3.3 Update the `findAll()` doubles in the unit tests of the touched classes to read `$config['filters']['schema']` and ignore the scope keys when matching rows.
- [x] 3.4 Run the full unit suite before and after; the failing set must be identical (14 inherited register tests).

## 4. Verify and ship

- [x] 4.1 Diff-scoped gates on touched files: `php -l`, phpcs, phpstan.
- [x] 4.2 Once before push: `composer check:strict`, `npm run lint`, `npm run format`, hydra gates.
- [x] 4.3 Open the PR against `development` with the OpenRegister file and line and the verification exit codes.

Documentation and i18n: not applicable, no user-facing text or screen changes.

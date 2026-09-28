# Tasks: example-set-regulation-rows

Cut from `origin/development`; the company (#1054) and training (#1051) sets are merged. Decision D29.

## Implementation Tasks

### Task 1: Amend the contract and its test (must, V1)
- **spec_ref**: `openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#requirement-a-schema-with-its-own-slug-pattern-takes-the-slug-from-the-object`
- **files**: `openspec/changes/segment-wizard-choice/contract.md`, `tests/Unit/Settings/ExampleSetDescriptorContractTest.php`
- **acceptance_criteria**:
  - GIVEN a regulation row with slug VCA WHEN the test runs THEN no finding
  - GIVEN the envelope form, a duplicate code, or a register-seeded code WHEN the test runs THEN each is reported
- [x] Implement
- [x] Test

### Task 2: Company regulations (must, V1)
- **spec_ref**: `openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#requirement-the-company-and-training-sets-carry-the-regulations-they-reference`
- **files**: `scripts/example-sets/corporate.py`, `lib/Settings/profiles/corporate.json`, `tests/Unit/Settings/CorporateExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN the company set WHEN every regulationSlug is collected THEN each resolves to a row in the set or to AVG
  - GIVEN the department audiences WHEN the certification check runs THEN it reads them from the rows
  - Existing uuids unchanged; objectCount and info.version updated
- [x] Implement
- [x] Test

### Task 3: Training regulations (must, V1)
- **spec_ref**: `openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#requirement-the-company-and-training-sets-carry-the-regulations-they-reference`
- **files**: `scripts/example-sets/training.py`, `lib/Settings/profiles/training.json`, `tests/Unit/Settings/TrainingExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN the training set WHEN every regulationSlug is collected THEN each resolves to a row in the set or to AVG
  - Existing uuids unchanged; objectCount and info.version updated
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate example-set-regulation-rows --strict` passes
- [x] Diff-scoped checks green (the three set tests, generators `--check`, check:register, check:schema-l10n)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds named in the PR)

## Quality checklist
- Seed data: the Regulation rows in the two example sets (design, Seed Data); no register change.
- i18n: no new UI strings; set content is Dutch, like the rest of the sets.

# Tasks: segment-example-datasets-training

Stacked on `segment-example-datasets-po` (learniq #1031), which is stacked on `segment-wizard-choice` (#1028, on #1022): uses the profiles directory, `SeedProfileService`, the descriptor contract test and the po generator's render format.

## Implementation Tasks

### Task 1: Generate the training institute set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-training/specs/example-sets/spec.md#requirement-the-training-institute-set-is-one-consistent-institute`
- **files**: `scripts/example-sets/training.py`, `lib/Settings/profiles/training.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it
- [x] Implement
- [x] Test

### Task 2: Prove the story is consistent (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-training/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened`
- **files**: `tests/Unit/Settings/TrainingExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every mark WHEN checked THEN it sits on a held session of the participant's edition and names the trainer on duty
  - GIVEN every held session WHEN grouped by trainer and room THEN nothing is double-booked
  - GIVEN every enrolment WHEN checked THEN certificates, attestations, tests, rebookings and renewals follow the rules
  - GIVEN the intake, the evaluations and the package reports WHEN counted THEN they agree with the enrolments, the quality scores and their entries
- [x] Implement
- [x] Test

### Task 3: Prove the set loads and removes cleanly (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-training/specs/example-sets/spec.md#requirement-the-training-institute-set-loads-and-removes-cleanly`
- **files**: `tests/Unit/Settings/TrainingExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, the institute last
  - GIVEN python3 WHEN available THEN the generator's `--check` passes inside the test
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-training --strict` passes
- [x] Diff-scoped checks green (contract test, content test, generator `--check`, check:register, check:schema-l10n, check:l10n-js, check:json-strict)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/feat/segment-example-datasets-po` run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test and the content test cover the set; a mutation of a mark and of an enrolment makes the content test fail.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already describes the sets and the remove command.
- i18n: the set's card description in en and nl; the label reuses "Training institute".

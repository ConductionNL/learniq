# Tasks: segment-example-datasets-he

Stacked on `segment-example-datasets-po` (learniq #1031, on #1028 and #1022): uses the profiles directory, `SeedProfileService` and the descriptor contract test.

## Implementation Tasks

### Task 1: Generate the higher education set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution`
- **files**: `scripts/example-sets/he.py`, `lib/Settings/profiles/he.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it
- [x] Implement
- [x] Test

### Task 2: Prove the grades, the BSA and the item analysis agree with learniq's own code (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-binding-study-advice-follows-the-grades`
- **files**: `tests/Unit/Settings/HigherEducationExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every final grade WHEN the real GradeAggregationEngine runs over its published entries THEN value, breakdown and passed match, and the enrolment outcome follows
  - GIVEN every BSA decision WHEN the passed credits are summed THEN they equal ectsAchieved, and a negative decision follows an issued warning
  - GIVEN every item statistic WHEN recomputed from the stored responses THEN sample size and p-value match, and every revision flag is justified
  - GIVEN marks, peer reviews and portfolios WHEN checked THEN each sits in its own cohort, group or share
- [x] Implement
- [x] Test

### Task 3: Prove the set loads and removes cleanly (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-loads-and-removes-cleanly`
- **files**: `tests/Unit/Settings/HigherEducationExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, children first, the institution last
  - GIVEN python3 WHEN available THEN the generator's `--check` passes inside the test
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-he --strict` passes
- [x] Diff-scoped checks green (contract test, content test, check:register, check:schema-l10n, check:l10n-js, generator `--check`)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test and the content test cover the set; the content test runs the real grade engine.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already describes the sets and the remove command.
- i18n: the set's card description in en and nl; the label reuses "Higher education (HBO or university)".

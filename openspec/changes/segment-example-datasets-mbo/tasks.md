# Tasks: segment-example-datasets-mbo

Stacked on `segment-example-datasets-po` (uses the profiles directory, `SeedProfileService`, the descriptor contract test and the po generator's shape). Tier: must (MVP), decision D21.

## Implementation Tasks

### Task 1: Generate the vocational college set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college`
- **files**: `scripts/example-sets/mbo.py`, `lib/Settings/profiles/mbo.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it, with no em-dash and no interpolated number
- [x] Implement
- [x] Test

### Task 2: Prove the story is consistent (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-work-placements-are-signed-visited-and-assessed`
- **files**: `tests/Unit/Settings/VocationalCollegeExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every lesson WHEN checked THEN none falls on a closed day or on a placement visit or assessment day of its class
  - GIVEN every mark WHEN checked THEN it sits on the student's class and is made by a teacher of that class and unit who works that weekday
  - GIVEN every placement WHEN checked THEN it is signed by three parties before it starts, visited by its BPV-docent and assessed by its praktijkopleider inside its period, and each PVB result follows the last assessment
- [x] Implement
- [x] Test

### Task 3: Prove results, advice and removal agree with the code (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines`
- **files**: `tests/Unit/Settings/VocationalCollegeExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every final grade WHEN recomputed with `GradeAggregationEngine` and `GradePassEvaluator` THEN value, passed and breakdown match
  - GIVEN every first-year decision WHEN checked THEN `ectsAchieved` equals the passed credits, and a negative decision references an issued warning and ends the enrolment
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, children first, the college last
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-mbo --strict` passes
- [x] Diff-scoped checks green (contract test, content test, `check:register`, `check:schema-l10n`, `check:l10n-js`, generator `--check`)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/development` run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test and `VocationalCollegeExampleSetTest` (10 tests, the final grades checked against the real grade engine classes).
- Newman and Playwright: N/A, no endpoint or UI change; loading goes through the existing wizard action covered by `segment-wizard-choice`.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already names the MBO set and the remove command; no change.
- i18n: the set's card description in en and nl; the label reuses "Vocational education (MBO)".

# Tasks: segment-example-datasets-vo

Stacked on `segment-example-datasets-po` (learniq #1031), itself on `segment-wizard-choice` (uses the profiles directory, `SeedProfileService` and the descriptor contract test).

## Implementation Tasks

### Task 1: Generate the secondary school set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-vo/specs/example-sets/spec.md#requirement-the-secondary-school-set-is-one-consistent-school`
- **files**: `scripts/example-sets/vo.py`, `lib/Settings/profiles/vo.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it
- [x] Implement
- [x] Test

### Task 2: Prove the story is consistent (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-vo/specs/example-sets/spec.md#requirement-grades-agree-with-the-records-derived-from-them`
- **files**: `tests/Unit/Settings/SecondarySchoolExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every mark WHEN checked THEN it sits on its pupil's class, a school day, on or after inschrijving, and a late arrival is marked by a class teacher working that weekday
  - GIVEN every report card WHEN checked THEN its attendance summary equals the marks of its period, and a subject line backed by a final grade shows that grade's period average and entries
  - GIVEN every final grade WHEN recomputed from its entries THEN value and breakdown match
  - GIVEN the profielkeuze, the schooladvies and the verzuim flags WHEN checked THEN they match the rules of the spec
- [x] Implement
- [x] Test

### Task 3: Prove the set loads and removes cleanly (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-vo/specs/example-sets/spec.md#requirement-the-secondary-school-set-loads-and-removes-cleanly`
- **files**: `tests/Unit/Settings/SecondarySchoolExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, the last-loaded first and the school last
  - GIVEN python3 WHEN available THEN the generator's `--check` passes inside the test
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-vo --strict` passes
- [x] Diff-scoped checks green (contract test, content test, schema-l10n, l10n-js, check:register, generator `--check`)
- [ ] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test and the content test cover the set.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already describes the sets and the remove command.
- i18n: the set's card description in en and nl; the label reuses "Secondary school".

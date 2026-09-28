# Tasks: segment-example-datasets-po

Stacked on `segment-wizard-choice` (uses the profiles directory, `SeedProfileService` and the descriptor contract test).

## Implementation Tasks

### Task 1: Generate the primary school set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school`
- **files**: `scripts/example-sets/po.py`, `lib/Settings/profiles/po.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it
- [x] Implement
- [x] Test

### Task 2: Retire the promoted seed blocks and repoint their tests (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-register-no-longer-carries-dark-primary-school-seeds`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Settings/{SchoolAndLocation,EnrolmentStatutoryFields,SchoolYearShape,Groepsplan,SubjectAndTeacherAssignment,CohortGroupPagePolish}RegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN the ten schemas are read THEN their `x-openregister-seed` is empty and their versions are bumped
  - GIVEN the six tests WHEN run THEN they read `po.json` by name or uuid, assert floors, and pass
- [x] Implement
- [x] Test

### Task 3: Prove the story is consistent and the set loads and removes cleanly (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-loads-and-removes-cleanly`
- **files**: `tests/Unit/Settings/PrimarySchoolExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every mark WHEN checked THEN it sits on its pupil's class, a school day, on or after inschrijving, marked by the teacher on duty
  - GIVEN every report card WHEN checked THEN its attendance summary equals the marks of its period
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, children first
  - GIVEN python3 WHEN available THEN the generator's `--check` passes inside the test
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-po --strict` passes
- [x] Diff-scoped checks green (contract test, content test, repointed tests, gate 108, gate 101, schema-l10n, check:specs, generator `--check`)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/development` run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test, the content test and six repointed register tests cover the set.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already describes the sets and the remove command.
- i18n: the set's card description in en and nl; the label reuses "Primary school".

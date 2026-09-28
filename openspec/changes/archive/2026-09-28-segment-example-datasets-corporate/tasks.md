# Tasks: segment-example-datasets-corporate

Stacked on `segment-example-datasets-po` (learniq #1031), which is stacked on `segment-wizard-choice` (#1028) and `segment-runtime-bridge` (#1022): uses the profiles directory, `SeedProfileService` and the descriptor contract test.

## Implementation Tasks

### Task 1: Generate the company set (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-company-set-is-one-consistent-company`
- **files**: `scripts/example-sets/corporate.py`, `lib/Settings/profiles/corporate.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the generator WHEN run twice, under different hash seeds THEN the file is identical (`--check` exits 0)
  - GIVEN the set WHEN the contract test runs THEN it passes
  - GIVEN the card copy WHEN looked up THEN en and nl carry it
- [x] Implement
- [x] Test

### Task 2: Retire the corporate seed row (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-register-no-longer-carries-the-dark-corporate-seed`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN `ExternalTrainingRecord` is read THEN its `x-openregister-seed` is empty and its version is 0.2.1, `info.version` 0.25.2
  - GIVEN the set WHEN searched by title THEN the NIS2 board awareness session is there for the director
- [x] Implement
- [x] Test

### Task 3: Prove the story is consistent and the set loads and removes cleanly (must, MVP)
- **spec_ref**: `openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-certificates-expire-and-renew-the-way-the-listener-does-it`
- **files**: `tests/Unit/Settings/CorporateExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN every credential that expires in the year WHEN checked THEN it is expired and its renewal enrolment, course and follow-up credential match
  - GIVEN the skills gap as the dashboard computes it WHEN compared with the plans THEN every gap has an open goal
  - GIVEN points, orders and entitlements WHEN summed and matched THEN they agree with their sources
  - GIVEN the service WHEN it lists and names the removal list THEN count and uuids match the file, children first
  - GIVEN a copy of the file with five deliberate corruptions WHEN the test runs THEN each corruption fails its own test (negative control, run once by hand)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-example-datasets-corporate --strict` passes
- [x] Diff-scoped checks green (contract test, content test, schema-l10n, l10n-js, check:register, check:specs, generator `--check`); the register tests carry 5 inherited reds that fail identically on the base
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: the contract test and the content test cover the set.
- Docs: `docs/installation.md` (from `segment-wizard-choice`) already describes the sets and the remove command.
- i18n: the card description in en and nl; the label reuses "Company".

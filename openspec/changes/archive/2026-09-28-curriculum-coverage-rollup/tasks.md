# Tasks: curriculum-coverage-rollup

## Implementation Tasks

### Task 1: Declare the CurriculumCoverage schema, demo rows and catalogue keys
- **spec_ref**: `openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-curriculumcoverage-is-a-derived-read-only-coverage-object-per-framework-year-and-subject`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`, `tests/Unit/Settings/CurriculumCoverageRegisterTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN `CurriculumCoverage` matches design.md (properties, readOnly, no lifecycle, authorization)
  - GIVEN gate-101, `check:schema-l10n` and the `tests/Unit/Register/` suite WHEN run THEN no new failure
- [x] Implement
- [x] Test

### Task 2: CurriculumCoverageCalculator
- **spec_ref**: `#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately`, `#requirement-coverage-is-bucketed-by-year-and-subject-with-totals`
- **files**: `lib/Service/CurriculumCoverageCalculator.php`, `tests/Unit/Service/CurriculumCoverageCalculatorTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the spec scenarios WHEN computed THEN every count, id list, depth and bucket matches
- [x] Implement
- [x] Test

### Task 3: CurriculumCoverageRollup
- **spec_ref**: `#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob`
- **files**: `lib/Service/CurriculumCoverageRollup.php`, `tests/Stubs/Service/ObjectService.php`, `tests/Unit/Service/CurriculumCoverageRollupTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN stored rows WHEN recomputed THEN only changed rows are saved and vanished buckets deleted
  - GIVEN a missing framework WHEN recomputed THEN its rows are deleted
- [x] Implement
- [x] Test

### Task 4: CurriculumCoverageRollupHandler and its registration
- **spec_ref**: `#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob`
- **files**: `lib/Listener/CurriculumCoverageRollupHandler.php`, `lib/AppInfo/Registrar/CoverageListenerRegistrar.php` (new, so BootListenerRegistrar stays under phpmd's coupling limit), `lib/AppInfo/Registrar/EventListenerWiring.php`, `lib/AppInfo/Registrar/BootListenerRegistrar.php` (its narrowing helper becomes public), `tests/Stubs/Event/ObjectDeletedEvent.php`, `tests/Unit/Listener/CurriculumCoverageRollupHandlerTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN a save of each of the six schemas WHEN handled THEN exactly the touched frameworks recompute
  - GIVEN a throwing rollup WHEN handled THEN nothing is rethrown
- [x] Implement
- [x] Test

### Task 5: occ learniq:curriculum-coverage:recompute
- **spec_ref**: `#requirement-an-occ-command-fills-coverage-for-existing-data`
- **files**: `lib/Command/RecomputeCurriculumCoverage.php`, `appinfo/info.xml`, `tests/Unit/Command/RecomputeCurriculumCoverageTest.php`
- **tier**: should (V1)
- **acceptance_criteria**:
  - GIVEN frameworks WHEN run without an option THEN all recompute and the count is printed; with `--framework` one; an unknown id exits 1
- [x] Implement
- [x] Test

## Verification
- `openspec validate curriculum-coverage-rollup --strict` passes
- The five test classes pass; `php -l`, phpcs, phpmd, phpstan on touched `lib/` files clean
- `composer check:strict` once before push, then the hydra gates

## Quality checklist
- PHPUnit for every new class, at least 3 methods each (ADR-009).
- Newman: N/A, no endpoint. Playwright: N/A, no page (change 4).
- Documentation (ADR-010): lands with the view in change 4, where the reader meets it.
- i18n (ADR-005): English and Dutch for every new schema string.

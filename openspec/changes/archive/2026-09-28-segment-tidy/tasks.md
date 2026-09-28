# Tasks: segment-tidy

Cut from `origin/development`. Decision D34.

## Implementation Tasks

### Task 1: Hide BSA and subject choices for a chosen company (must, V1)
- **spec_ref**: `openspec/changes/segment-tidy/specs/nextcloud-app/spec.md#requirement-the-company-segment-also-hides-study-advice-and-subject-choices`
- **files**: `src/manifest.json`, `src/manifest.d/progress-decisions.json`, `src/manifest.d/progress.json`, `src/manifest.d/learning.json`, `tests/unit-js/segmentMenuGates.test.mjs`, `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN chose corporate WHEN built THEN the 4 BSA cards, the BSA report card and both subject choice menus are hidden
  - GIVEN never chose or he WHEN built THEN BSA shows
- [x] Implement
- [x] Test

### Task 2: Record loaded sets and remove one by id (must, V1)
- **spec_ref**: `openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button`
- **files**: `lib/Service/LoadedExampleSets.php`, `lib/Service/SeedProfileService.php`, `lib/Controller/SetupController.php`, `lib/Controller/PageController.php`, `tests/Unit/Service/LoadedExampleSetsTest.php`, `tests/Unit/Service/SeedProfileServiceTest.php`, `tests/Unit/Controller/SetupControllerTest.php`, `tests/Unit/Controller/PageControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a load WHEN it succeeds THEN the set is recorded with its label
  - GIVEN remove-example-set-<id> WHEN run THEN that set is removed; an unknown id is 400
  - GIVEN any state WHEN status is read THEN every remove-example-set-<id> is done
- [x] Implement
- [x] Test

### Task 3: One wizard step per loaded set (must, V1)
- **spec_ref**: `openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button`
- **files**: `src/utils/exampleSetSteps.js`, `src/main.js`, `l10n/en.json`, `l10n/nl.json`, `l10n/nl.js`, `tests/unit-js/exampleSetSteps.test.mjs`, `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN two loaded sets WHEN the page boots THEN the wizard has two removal steps, each with its own action
  - GIVEN none loaded WHEN the page boots THEN the single step stays
- [x] Implement
- [x] Test

## Verification
- [x] `node --test tests/unit-js/segmentMenuGates.test.mjs tests/unit-js/exampleSetSteps.test.mjs tests/unit-js/setupSteps.test.mjs`
- [x] PHPUnit for the touched classes
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates

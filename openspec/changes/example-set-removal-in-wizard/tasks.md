# Tasks: example-set-removal-in-wizard

Cut from `origin/development`. Uses openregister PR 4080 (`ConfigurationService::softDeleteAppImports`, merged 2026-09-27).

## Implementation Tasks

### Task 1: Remove a set through OpenRegister's import jobs (must, V1)
- **spec_ref**: `openspec/changes/example-set-removal-in-wizard/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs`
- **files**: `lib/Service/SeedProfileService.php`, `lib/Service/DemoDataService.php`, `tests/Unit/Service/SeedProfileServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a shipped set WHEN removed THEN softDeleteAppImports is called with learniq.profile.<id>; the generated set uses learniq.demo
  - GIVEN a ConfigurationService without the method WHEN removed THEN the result says unsupported, nothing is called
- [x] Implement
- [x] Test

### Task 2: The wizard step and its action (must, V1)
- **spec_ref**: `openspec/changes/example-set-removal-in-wizard/specs/example-sets/spec.md#requirement-the-removal-step-never-runs-by-itself`
- **files**: `lib/Controller/SetupController.php`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`, `tests/Unit/Controller/SetupControllerTest.php`, `tests/unit-js/setupSteps.test.mjs`
- **acceptance_criteria**:
  - GIVEN any state WHEN status is read THEN remove-example-set is done
  - GIVEN a loaded set WHEN the action runs THEN the soft-deleted count is reported and the load step stays answered
  - GIVEN None, no job, errors, or no method WHEN the action runs THEN each answer says what happened and names the occ command where one helps
- [x] Implement
- [x] Test

### Task 3: The segment answer checks the groups (must, V1)
- **spec_ref**: `openspec/changes/example-set-removal-in-wizard/specs/example-sets/spec.md#requirement-only-an-administrator-or-an-administration-manager-chooses-the-kind-of-organisation`
- **files**: `lib/Controller/SetupController.php`, `tests/Unit/Controller/SetupControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a user in neither group WHEN they post a segment THEN 403 and nothing is written
  - GIVEN an admin or an administration manager WHEN they post a segment THEN it is written with them as setter
- [x] Implement
- [x] Test

### Task 4: Contract and docs (should, V1)
- **spec_ref**: `openspec/changes/example-set-removal-in-wizard/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs`
- **files**: `openspec/changes/segment-wizard-choice/contract.md`, `docs/installation.md`
- **acceptance_criteria**:
  - The contract lists the action, the done-always status and the 403; the docs say how to remove a set from the wizard and with occ
- [x] Implement

## Verification
- [x] All tasks checked off
- [x] `openspec validate example-set-removal-in-wizard --strict` passes
- [x] Diff-scoped checks green (php -l, phpcs, phpstan, phpunit on the touched classes, node setupSteps test, check:specs, l10n checks)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds named in the PR)

## Quality checklist
- Tests: SeedProfileServiceTest (app ids, duck typing), SetupControllerTest (status, every action outcome, the group check), setupSteps node test.
- i18n: the new step title and body and the changed load body, en and nl.
- Seed data: no schema change.

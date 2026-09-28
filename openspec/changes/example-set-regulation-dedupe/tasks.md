# Tasks: example-set-regulation-dedupe

## Implementation Tasks

### Task 1: Leave out regulation codes another set created (must, V1)
- **spec_ref**: `openspec/changes/example-set-regulation-dedupe/specs/example-sets/spec.md#requirement-a-second-example-set-does-not-duplicate-a-regulation-code`
- **files**: `lib/Service/SharedCodeFilter.php`, `lib/Service/SeedProfileService.php`, `tests/Unit/Service/SharedCodeFilterTest.php`, `tests/Unit/Service/SeedProfileServiceTest.php`, `tests/Unit/Settings/*ExampleSetTest.php`, `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN company loaded WHEN training is filtered THEN exactly VCA and NIS2 are left out
  - GIVEN the same set WHEN filtered THEN nothing is left out
  - GIVEN a failing read WHEN filtered THEN the descriptor is unchanged
- [x] Implement
- [x] Test

## Verification
- [x] PHPUnit SharedCodeFilterTest, SeedProfileServiceTest, the example set tests
- [x] `composer check:strict`, `npm run lint`, hydra gates

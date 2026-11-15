# Tasks: training-set-qti-2-1

## Implementation Tasks

### Task 1: Regenerate the training items as QTI 2.1 (must, V1)
- **spec_ref**: `openspec/changes/training-set-qti-2-1/specs/example-sets/spec.md#requirement-the-training-set-writes-its-items-as-qti-21`
- **files**: `scripts/example-sets/training.py`, `lib/Settings/profiles/training.json`, `tests/Unit/Settings/TrainingExampleSetTest.php`
- **acceptance_criteria**:
  - GIVEN any training item WHEN QtiChoiceOrderResolver reads it THEN three options come back with the correct one among them
  - GIVEN the regenerated file WHEN diffed THEN only item rows and the version changed
- [x] Implement
- [x] Test

## Verification
- [x] `python3 scripts/example-sets/training.py --check`
- [x] PHPUnit TrainingExampleSetTest and ExampleSetDescriptorContractTest
- [x] `composer check:strict`, hydra gates

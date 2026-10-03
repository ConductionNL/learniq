# Tasks: lvs-score-freeze

## Implementation Tasks

### Task 1: Freeze a verified score
- **spec_ref**: `openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed`
- **files**: `lib/Listener/LvsResultFreezeListener.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `lib/Settings/learniq_register.json`, `tests/Unit/Listener/LvsResultFreezeListenerTest.php`
- **acceptance_criteria**:
  - score fields fixed once verified or archived; verified only to archived; imported still correctable; admin and system context pass; registered on update; register description no longer says append-only
- [x] Test written first and red
- [x] Implement
- [x] Test green

## Verification
- [x] `openspec validate lvs-score-freeze` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

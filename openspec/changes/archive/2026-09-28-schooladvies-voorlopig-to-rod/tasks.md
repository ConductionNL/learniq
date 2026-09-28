# Tasks: schooladvies-voorlopig-to-rod

## Implementation Tasks

### Task 1: Send the voorlopig advice once it is given
- **spec_ref**: `openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given`
- **files**: `lib/Listener/SchoolAdviesVoorlopigRodHandler.php`, `lib/BackgroundJob/SchoolAdviesVoorlopigRodJob.php`, `lib/Service/SchoolAdviesRodTiming.php`, `lib/AppInfo/Registrar/TransitionBridgeListenerRegistrar.php`, `lib/Settings/learniq_register.json`, `tests/Unit/Listener/SchoolAdviesVoorlopigRodTest.php`
- [x] Test written first and red
- [x] Implement

## Verification
- [x] `openspec validate schooladvies-voorlopig-to-rod` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

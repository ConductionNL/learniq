# Tasks: in-app-test-limits-server-side

## Implementation Tasks

### Task 1: One attempts rule and the start rules on the server
- **spec_ref**: `openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused`
- **files**: `lib/Service/AssessmentAccessPolicy.php`, `lib/Service/Portal/PortalAssessmentCatalogue.php`, `lib/Service/AssessmentAttemptLimits.php`, `lib/Listener/AssessmentAttemptGateListener.php`, their tests
- **acceptance_criteria**:
  - a second attempt on a one-attempt test is refused with `attempts-used`; the portal catalogue uses the same rule
  - the server sets `startedAt` and `attemptNumber` on create
- [x] Implement
- [x] Test

### Task 2: The time limit on saves
- **spec_ref**: `openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved`
- **files**: `lib/Listener/AssessmentAttemptTimeLimitListener.php`, `lib/AppInfo/Registrar/AttemptLimitListenerRegistrar.php` (called from `SchedulingListenerRegistrar`), `tests/Unit/Listener/AssessmentAttemptTimeLimitListenerTest.php`
- **acceptance_criteria**:
  - start and number fixed; late answers kept out; a late hand-in goes through; scores on unchanged answers kept; grace and extra time respected; registered
- [x] Implement
- [x] Test

### Task 3: The screen names the refusal
- **spec_ref**: `openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused`
- **files**: `src/views/TakeAssessmentView.vue`, `l10n/`
- **acceptance_criteria**:
  - `attempts-used` shows a translated sentence
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate in-app-test-limits-server-side` passes
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates, each with its exit code in the PR body

## Quality checklist

- Dutch catalogue value for the new sentence

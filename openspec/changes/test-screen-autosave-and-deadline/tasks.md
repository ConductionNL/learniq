# Tasks: test-screen-autosave-and-deadline

## Implementation Tasks

### Task 1: The server stamps and fixes deadlineAt
- **spec_ref**: `openspec/changes/test-screen-autosave-and-deadline/specs/assessment/spec.md#scenario-the-timer-counts-down-to-the-servers-deadline`
- **files**: `lib/Service/AssessmentAttemptLimits.php`, `lib/Listener/AssessmentAttemptTimeLimitListener.php`, `lib/Settings/learniq_register.json`, their tests
- [x] Test written first and red
- [x] Implement

### Task 2: The screen counts down to it and autosaves
- **spec_ref**: `openspec/changes/test-screen-autosave-and-deadline/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works`
- **files**: `src/utils/attemptClock.js`, `src/views/TakeAssessmentView.vue`, `tests/unit-js/attemptClock.test.mjs`, `l10n/`
- [x] Test written first and red
- [x] Implement

## Verification
- [x] `openspec validate test-screen-autosave-and-deadline` passes
- [x] `composer check:strict`, `npm run lint`, `npm run test:js-unit`, hydra gates, each with its exit code in the PR body

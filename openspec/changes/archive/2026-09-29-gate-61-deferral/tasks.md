# Tasks: gate-61-deferral

## Implementation Tasks

### Task 1: Queue the xAPI follow-up and run it in a background job
- **spec_ref**: `openspec/changes/gate-61-deferral/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it`
- **files**: `lib/Listener/LessonProgressHandler.php`, `lib/Lifecycle/XapiCompletionHandler.php`, `lib/Service/LessonProgress.php`, `lib/Service/XapiEnrolmentCompletion.php`, `lib/BackgroundJob/XapiStatementFollowUpJob.php`, tests
- [x] Tests written first and red
- [x] Implement

## Verification
- [x] `openspec validate gate-61-deferral` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates (gate 61 PASS), each with its exit code in the PR body
  - Done on the originating PR #1233: `composer check:strict` exit 0 (2183 tests, 0 failures, phpmd 0), `npm run lint` 0, hydra gates exit 8 with gate 61 PASS and only inherited reds (gates 3, 25, 49, 53, 55, 60, 112, 113, none on its files). check:strict, lint and the hydra gates were re-run on current development in the r5-structure part 2 PR (exit codes in its body); gate 61 is delta-scoped and has no delta there, so #1233 remains its evidence.

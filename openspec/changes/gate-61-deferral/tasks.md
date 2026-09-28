# Tasks: gate-61-deferral

## Implementation Tasks

### Task 1: Queue the xAPI follow-up and run it in a background job
- **spec_ref**: `openspec/changes/gate-61-deferral/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it`
- **files**: `lib/Listener/LessonProgressHandler.php`, `lib/Lifecycle/XapiCompletionHandler.php`, `lib/Service/LessonProgress.php`, `lib/Service/XapiEnrolmentCompletion.php`, `lib/BackgroundJob/XapiStatementFollowUpJob.php`, tests
- [x] Tests written first and red
- [x] Implement

## Verification
- [x] `openspec validate gate-61-deferral` passes
- [ ] `composer check:strict`, `npm run lint`, hydra gates (gate 61 PASS), each with its exit code in the PR body

# Tasks: learner-lookup-and-learnerrefs-fixes

Tier: must (MVP). Report cards, parent notifications and the portal depend on it.

## 1. Lookups

- [x] 1.1 Scan every LearnerProfile `findAll()` in `lib/` for the filter key; four use `learnerId`.
- [x] 1.2 `ReportCardComposer`: stamp `learnerRef` through `LearnerRefResolver`; remove the private `resolveLearnerRef()`.
- [x] 1.3 `GradeRollupHandler` and `ReportCardPublishHandler`: read the profile on `ncUserId` without RBAC.
- [x] 1.4 `LearningPlanSignatureGuard`: read the profile on `ncUserId` without RBAC, and find the template through `ids` instead of a `uuid` filter.

## 2. Submission stamp

- [x] 2.1 Add `lib/Listener/SubmissionLearnerRefsStamp.php`, mirroring `GradeEntryLearnerRefStamp` from PR 1020.
- [x] 2.2 Register it on `ObjectCreatingEvent` and `ObjectUpdatingEvent` in `IntegrityListenerRegistrar`.

## 3. Tests

- [x] 3.1 `ReportCardComposerTest`: the learner-profile double answers through `RegisterFaithfulStore`; new test for the stamped `learnerRef`.
- [x] 3.2 `GradeRollupHandlerTest`: new test that notifications reach only the learner's own parents, read without RBAC.
- [x] 3.3 `ReportCardPublishHandlerTest`: the profile double answers through `RegisterFaithfulStore`, keyed on `ncUserId`.
- [x] 3.4 New `LearningPlanSignatureGuardTest` (3 tests) over the faithful store.
- [x] 3.5 New `SubmissionLearnerRefsStampTest` (11 tests).
- [x] 3.6 Show every new or changed test fails on the previous code.

## 4. Verify and ship

- [x] 4.1 Diff-scoped gates on touched files, then `composer check:strict`, `npm run lint`, `npm run format` and the hydra gates once.
- [x] 4.2 Open the PR stacked on #1047 and #1020, with the landing order.

Documentation and i18n: not applicable, no user-facing text or screen changes.

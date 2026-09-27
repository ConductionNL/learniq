# LANE-LOG: r2-assessment (learniq round 2, lane C)

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-reports. Brief: six changes in order, one branch and PR each, cut
from origin/development with --no-track. Logs of each run under `.tmp/` in this clone.

## 1. gradeentry-learnerref-stamp: DONE
- Branch: feat/gradeentry-learnerref-stamp (2 commits, pushed, ls-remote 2c48572)
- PR: https://github.com/ConductionNL/learniq/pull/1020
- Built: LearnerRefResolver (ncUserId, nested filters, merge survivor), GradeEntryLearnerRefStamp (create+update,
  IntegrityListenerRegistrar), BackfillGradeEntryLearnerRef repair step + info.xml version bump (gate 110),
  tests/Support/RegisterFaithfulStore (fake that answers like OR), 22 tests.
- Verification: diff checks 0; check:strict 1 (inherited: phpmd coupling in Case/SchedulingListenerRegistrar,
  14 register/seed tests = #1006); lint 0; format 0; schema-l10n 0; gates 1 (gate 112 newman-reach, inherited).
- opsx-verify: pass (one suggestion: no docs page for portal grade visibility).
- Findings for the orchestrator: ReportCardComposer::resolveLearnerRef and GradeRollupHandler::fanOutParentNotifications
  filter learner-profile on `learnerId` (undeclared; returns zero rows). ~170 findAll() call sites put register/schema at
  the top level (inert in OR's prepareFindAllConfig). Submission portal scoping uses `learnerRefs`, which nothing stamps.
- Time: ~1h40.

## 2. assignment-missing-submissions-view: DONE
- Branch: feat/assignment-missing-submissions-view (2 commits, pushed, ls-remote b720b85)
- PR: https://github.com/ConductionNL/learniq/pull/1023
- Built: src/utils/handInStatus.js (+7 node tests), src/components/sections/AssignmentHandInStatus.vue (kind: section,
  staff-only via dashboardRoles), AssignmentDetail.config.bodyWidgets, registry coverage test for bodyWidgets, en/nl strings.
- Verification: node tests 0; check:manifest/specs/l10n-js 0; check:strict 1 (same inherited set); lint/format/schema-l10n 0;
  shared gates 1 (gate 16 fixed, gate 53 env ESM failure, gate 112 inherited); vendored gates 0 (gate 53 PASS).
- opsx-verify: pass.
- Note: LANE-LOG.md is TRACKED in development (committed by #910, an earlier lane). Never `git add` it.
- Time: ~50 min.

## 3. submission-resubmission-action: DONE
- Branch: feat/submission-resubmission-action (1 commit, pushed, ls-remote 46126bb)
- PR: https://github.com/ConductionNL/learniq/pull/1027
- Built: Submission.resubmissionDueAt; reopen gets required input + staff authorization + resubmissionRequested notification;
  SubmissionWindowGuard honours the date; SubmissionResubmissionDateListener (learners cannot set/move it);
  SubmissionDetail lifecycleActions (reopen only). Submission 0.3.0, register 0.24.10.
- Verification: targeted phpunit 0 (26); Register suite same 5 inherited; check:strict 1 (inherited set); lint/format 0;
  shared gates 2 (53 env, 112 inherited); vendored gates 0.
- opsx-verify: pass. Known limit: pupils see the reopen button (server refuses).
- Time: ~1h10.

## 4. peer-review-allocation-trigger: DONE (stacked on #1023)
- Branch: feat/peer-review-allocation-trigger, cut from origin/feat/assignment-missing-submissions-view (pushed, 9afd52a)
- PR: https://github.com/ConductionNL/learniq/pull/1030 (base development; land #1023 first)
- Built: AssignmentPeerReviewAllocation section (+ peerReviewAllocation.js, 6 node tests); PeerReviewAllocationService fixed:
  filters-nested register/schema, limits, _rbac:false behind the controller's check, handed-in work only; service test
  now answers like OR.
- Verification: phpunit PeerReview 0 (21); node 0 (17); check:strict 1 (inherited set); lint/format 0; shared gates 2
  (53 env, 112 inherited); vendored 0.
- opsx-verify: pass.
- Finding: PeerReviewMarkingView shows the reviewer no work at all; pupil reviewers cannot read the Submission. Change 6
  addresses it via a projection endpoint.
- Time: ~1h.

## 5. cohort-gradebook-batch-publish: DONE
- Branch: feat/cohort-gradebook-batch-publish (2 commits, pushed, 72501f5)
- PR: https://github.com/ConductionNL/learniq/pull/1033
- Built: gradebookPublish.js (8 node tests); publish panel in CohortGradebookView (scope, stats, histogram, confirmed
  sequential publish transitions, refusal report). The grid itself already existed (learniq#947).
- Verification: node 0; check:strict 1 (inherited set); lint/format/l10n 0; shared gates 2 (16 fixed, 112 inherited);
  vendored gates 0 after fix.
- opsx-verify: pass. Not done: one notification per recipient per batch (needs a notification change).
- Time: ~50 min.

## 6. peer-review-projection-guard: DONE
- Branch: feat/peer-review-projection-guard (2 commits, pushed, c4e8b32)
- PR: https://github.com/ConductionNL/learniq/pull/1044
- Built: PeerReviewWorkProjection + PeerReviewWorkController (GET work, GET work/files/{fileId}), two routes;
  PeerReviewMarkingView reads the projection and shows the work; Assignment.peerReviewAnonymity description,
  Assignment 0.4.0, register 0.24.11; spec: anonymity requirement REMOVED + re-ADDED with a server-enforced 4th
  scenario (MODIFIED may not drop a scenario); regression node test for the view.
- Verification: phpunit 0 (39); Register same 5 inherited; check:strict 1 (inherited set); lint/format 0; shared gates 2
  (53 env, 112 inherited; 5/7/8/14/16/17/48/49/50 PASS); vendored 0.
- opsx-verify: pass after adding the view test.
- Time: ~1h30.

## CI read (once, end of lane, 2026-09-27)
- #1020, #1023, #1027, #1030, #1033: identical 4 reds on every PR, all inherited. Hydra Gates in CI reads the WHOLE
  tree (gate package 9ca0732, "File scope: FULL"): gate 3 stub-scan (6), 25 contract-coverage (2), 49 (1), 55 (3), the
  same counts on #1020 which touches no controller or manifest. PHPUnit: the same 1 error + 13 failures, all under
  tests/Unit/Register and tests/Unit/Settings (#1006). phpmd: the two inherited registrars. No NEW red, nothing to fix.
- #1044: checks still pending at the one read.

## Lane status: ALL SIX DONE. Landing order: #1020, #1023 then #1030 (stacked), #1027, #1033, #1044.

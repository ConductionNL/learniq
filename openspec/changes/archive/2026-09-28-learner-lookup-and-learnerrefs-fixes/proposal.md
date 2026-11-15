---
kind: code
depends_on: [findall-config-filters-sweep, gradeentry-learnerref-stamp]
---

# Proposal: learner-lookup-and-learnerrefs-fixes

## Summary

Four LearnerProfile lookups filter on `learnerId`, a property LearnerProfile does not have. OpenRegister answers a filter on an undeclared property with no rows, so report cards never got a `learnerRef`, parents never got a grade or report card notification, and parent co-signs on a learning plan were never verified. This change looks the profile up on `ncUserId`, fixes the learning plan template lookup that let every plan activate unsigned, and stamps `Submission.learnerRefs` on every write so the portal's student submissions collection fills.

## Motivation

The r2-assessment lane found two of these on 2026-09-27 (TRACKER-R2, 16:05): `ReportCardComposer::resolveLearnerRef()` and `GradeRollupHandler::fanOutParentNotifications()`. A scan of every LearnerProfile read in `lib/` found two more of the same shape: `ReportCardPublishHandler::fanOutParentNotifications()` and `LearningPlanSignatureGuard::filterVerifiedParentSignatures()`.

LearnerProfile declares `ncUserId`, not `learnerId` (`lib/Settings/learniq_register.json`). OpenRegister's `MagicSearchHandler::applyObjectFilters()` (openregister `lib/Db/MagicMapper/MagicSearchHandler.php`, lines 2366-2376 at `d611a366`) adds `1 = 0` for a filter on a property the schema does not declare. Once `findall-config-filters-sweep` scopes these reads to the right schema, they return no rows; before it, they had no schema at all and returned nothing either.

The same guard had a worse defect next to it. `LearningPlanSignatureGuard::fetchRequiredRoles()` found the template on `uuid`, which LearningPlanTemplate does not declare either. No template meant no required signers, so the guard allowed every `draft → active` transition without a signature.

The portal scopes a pupil's submissions on `Submission.learnerRefs` (`PortalContributionProvider::studentActivityCollections()`), and nothing set it. PR 1020 (`gradeentry-learnerref-stamp`) fixed the same gap for GradeEntry with a write-path stamp and a shared `LearnerRefResolver`; this change reuses both.

## Affected Projects

- [x] Project: `learniq`: four lookups, one guard lookup, one new listener and its registration, tests.

## Scope

### In Scope

- `ReportCardComposer` stamps `learnerRef` through `LearnerRefResolver` (ncUserId, merge survivor first, no RBAC).
- `GradeRollupHandler`, `ReportCardPublishHandler` and `LearningPlanSignatureGuard` read the profile on `ncUserId`, without RBAC, since only `parentIds` is used and the caller (a teacher, team lead or parent) may not read LearnerProfile.
- `LearningPlanSignatureGuard::fetchRequiredRoles()` finds the template through `ids` instead of a `uuid` filter.
- New `SubmissionLearnerRefsStamp` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`: derives `learnerRefs` from `learnerIds`, ignores a client value, never blocks a write.
- Tests over the OpenRegister-faithful store from PR 1020 (`tests/Support/RegisterFaithfulStore.php`), each shown to fail on the old code.

### Out of Scope

- A backfill of `learnerRefs` on existing Submissions. New and updated submissions get it; a repair step like `BackfillGradeEntryLearnerRef` is a follow-up.
- The 22 other reads that filter on `id` or `uuid` (listed in PR 1047). Only the one inside the guard this change already touches is fixed here.
- `RolloverExecutionService::queueOutflow()` writes `learnerId` into a DataExchangeJob scope. That is a key `OsoDossierReviewGuard` reads by name, not an OpenRegister filter, and data exchange moves to integriq (D7).

## Approach

Reuse `LearnerRefResolver` where the UUID is all that is needed. Where the profile row is needed (`parentIds`), keep the existing read and correct the filter key, following `AssessmentResultAudience::learnerManager()`, which already reads the profile on `ncUserId` without RBAC. The Submission stamp mirrors `GradeEntryLearnerRefStamp` line for line, with a list instead of a single value.

## New Dependencies

None.

## Impact

Report card composition and publication, grade publication, learning plan activation, and every Submission write. No schema, route or UI change.

## Cross-Project Dependencies

Portaliq reads `Submission.learnerRefs` through learniq's portal contribution; no portaliq change is needed. Stacked on learniq #1047 (findall-config-filters-sweep) and learniq #1020 (gradeentry-learnerref-stamp), which provides `LearnerRefResolver` and `RegisterFaithfulStore`.

## Risks

### Risk 1: learning plans that activated unsigned now stay in draft

**Severity:** High. **Mitigation:** that is the intended fix: a template's required signers now apply. A school with plans in flight may see activations refused until the parent and teacher sign. Named in the PR body so the landing can tell schools.

### Risk 2: parents start receiving notifications they never received

**Severity:** Medium. **Mitigation:** intended. The notification records already carry idempotency keys, so a re-published grade does not notify twice.

### Risk 3: reading the profile without RBAC

**Severity:** Low. **Mitigation:** only the profile UUID or `parentIds` leaves each method, never to the caller. `AssessmentResultAudience` and `LearnerRefResolver` already read this way.

## Rollback Strategy

Revert the merge commit. The stamp writes only `learnerRefs`, which the portal treats as optional.

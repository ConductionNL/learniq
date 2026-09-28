---
kind: code
depends_on: [learner-lookup-and-learnerrefs-fixes, assignment-portal-wiring]
---

# Proposal: learnerrefs-backfill-and-lookup-dedupe

## Summary

Submissions written before the server stamped `learnerRefs` (#1056) and `learnerRef` (#1068) never reach the portal, because the portal scopes a pupil's submissions on those fields. A repair step stamps them on existing rows, once, idempotently. And the learner profile lookup the portal work added twice (`Portal\LearnerProfileLookup`, #1068 and #1096) folds into `LearnerRefResolver` from #1020, so one class answers "which profile is this learner".

## Motivation

- **Old submissions stay dark.** `SubmissionLearnerRefsStamp` and `SubmissionOwnerStamp` stamp every write from now on, but nothing touched the rows already there. #1020 added `BackfillGradeEntryLearnerRef` for grades; submissions had no equivalent (TRACKER-R2, r2-portal-learniq follow-ups).
- **Two lookups, one job.** `LearnerProfileLookup::refForUser()` says in its own docblock that it "does what PR 1020's `LearnerRefResolver::resolve()` does" and that "the two fold into one once 1020 lands". It was added in #1068 and again in #1096 (the merges unioned the file). Two copies of a lookup that had four different bugs across the fleet today (#1047, #1056) is where the next one hides.

## Affected Projects

- [ ] Project: `learniq`: `LearnerRefResolver`, `Portal\LearnerProfileLookup` (deprecated facade), `SubmissionOwnerStamp`, `AssessmentResultPortalStamp`, `PortalLearnerResolver`, new `Repair\BackfillSubmissionLearnerRefs`, `appinfo/info.xml`.

## Scope

### In Scope

- `LearnerRefResolver` gains `byRef()` (the active profile a `learnerRef` names) and `resolveAcrossTenants()` (for callers without a session); `resolve()` is unchanged for signed-in callers.
- The three callers of `LearnerProfileLookup` use `LearnerRefResolver`.
- `LearnerProfileLookup` becomes a deprecated one-line facade, because #1129 (open) adds a caller; it is deleted once no caller is left.
- `BackfillSubmissionLearnerRefs`, a post-migration repair step after `InitializeSettings`.

### Out of Scope

- The other LearnerProfile-by-`ncUserId` reads (`LearningPlanSignatureGuard`, `GradeRollupHandler`, `PokParentSignatureRule` in #1143). They read more than the uuid and are correct since #1056; folding them is its own change.

## Approach

Move the two reads into `LearnerRefResolver` without changing what any caller reads: signed-in callers keep tenant scoping, portal callers keep reading across tenants. The repair step mirrors `BackfillGradeEntryLearnerRef`: pages every Submission without RBAC or tenant scoping, derives the values the write-path stamps would store today, and saves only rows whose stored values differ.

## New Dependencies

None.

## Impact

Old submissions appear in the pupil's portal after the upgrade. No behaviour change for any lookup.

## Cross-Project Dependencies

None. #1129 keeps working through the facade whichever lands first.

## Risks

### Risk 1: A stale stored value is corrected

**Severity:** Low. **Mitigation:** A row whose stored `learnerRefs` or `learnerRef` differs from what the stamps derive now is rewritten to the derived value, the same value the next ordinary save would write. A failed lookup never overwrites.

## Rollback Strategy

Revert the merge commit. Stamped values stay on the rows; they are what the listeners write anyway.

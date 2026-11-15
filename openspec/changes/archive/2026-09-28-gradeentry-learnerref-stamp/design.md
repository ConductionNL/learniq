# Design: gradeentry-learnerref-stamp

## Architecture Overview

```
any creator (GradeRollupHandler, MarkSubmissionView, CohortGradebookView,
  LtiAgsScorePollJob, ExemptionGrantHandler, Werkproces/PortfolioGradeEmitHandler,
  generic create form)
        |
        v  OpenRegister saveObject()
  ObjectCreatingEvent / ObjectUpdatingEvent (grade-entry)
        |
        v
  GradeEntryLearnerRefStamp --> LearnerRefResolver --> learner-profile (ncUserId)
        |
        v  setModifiedData(['learnerRef' => uuid|null])
  row stored with learnerRef --> portal collections scoped on learnerRef
```

The stamp sits on the write path, so every present and future creator is covered without a change
to any of them. `BackfillGradeEntryLearnerRef` uses the same resolver for rows written before this
change.

## Nextcloud Integration
- Services: `OCA\Learniq\Service\LearnerRefResolver` (new), `OCA\OpenRegister\Service\ObjectService`.
- Events: `OCA\OpenRegister\Event\ObjectCreatingEvent`, `ObjectUpdatingEvent`, registered in
  `IntegrityListenerRegistrar` beside the other pre-write listeners.
- Repair: `OCP\Migration\IRepairStep`, registered under `<post-migration>` after
  `InitializeSettings` (the register must exist first).

## Decisions

### D1: One write-path listener, not a patch per creator
The recon proposed patching `GradeRollupHandler` and `MarkSubmissionView`. Eight creators exist, and
a ninth will be added. A pre-write listener covers all of them and cannot be forgotten by the next
one. Alternative rejected: a client-side field on each Vue view, which a forged request bypasses.

### D2: Server-derived, client value ignored
`learnerRef` decides which guardians see a grade. Letting a caller choose it would let a teacher
route a grade to another pupil's parents. Same posture as `AssessmentResultAudience`.

### D3: Resolve on `ncUserId`, nested `filters`
LearnerProfile declares `ncUserId`, not `learnerId`. OpenRegister's search answers an undeclared
filter key with `1 = 0`, which is why `ReportCardComposer::resolveLearnerRef()` always returns null.
`ObjectService::prepareFindAllConfig()` only reads `filters.register` / `filters.schema`, so the
resolver nests both there. It reads with `_rbac: false`: a team lead may create a grade but cannot
read LearnerProfile, and the lookup is the server's, not the caller's.

### D4: Merge survivor first
`LearnerMergeService` sets `mergedInto` on the merged-away profile. The resolver reads up to ten
profiles for the user and takes the first with an empty `mergedInto`, else the first.

### D5: Update keeps the stored value on a lookup error
A transient database error must not hide a grade that is already visible. On create there is no
stored value, so the error path stamps null (fail-closed).

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Derive `learnerRef` from `learnerId` | imperative listener | A cross-schema lookup (GradeEntry to LearnerProfile by a non-key field). `x-openregister-calculations` evaluate over the object's own fields and cannot query another schema. ADR-031 exception: pre-write guard/stamp. |
| Back-fill existing rows | imperative repair step | One-off data transformation on upgrade; no schema declaration expresses it. |

## Security Considerations
- Closes a routing risk: without D2 any caller with create rights on GradeEntry could choose the
  portal subject.
- The resolver bypasses RBAC for one read of a UUID; it never returns profile data to the caller.
- No new endpoint, no new permission.

## File Structure
```
lib/
  Service/LearnerRefResolver.php                 (new)
  Listener/GradeEntryLearnerRefStamp.php         (new)
  Repair/BackfillGradeEntryLearnerRef.php        (new)
  AppInfo/Registrar/IntegrityListenerRegistrar.php (two registrations)
appinfo/info.xml                                 (one post-migration step)
tests/Unit/Service/LearnerRefResolverTest.php
tests/Unit/Listener/GradeEntryLearnerRefStampTest.php
tests/Unit/Repair/BackfillGradeEntryLearnerRefTest.php
```

## Seed Data
No schema is introduced or changed. The existing GradeEntry seed rows gain a `learnerRef` the first
time the repair step runs after demo data loads, when their learner has a seeded profile.

## Trade-offs
- The listener costs one indexed read per GradeEntry write. Grade writes are human-paced; the LTI
  poll job writes in small batches.
- The register description of `learnerRef` still says "until backfilled". Left as is: changing it
  would bump the schema version for a comment. The statement stays true for unresolvable rows.

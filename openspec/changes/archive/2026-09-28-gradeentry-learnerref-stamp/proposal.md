---
kind: code
---

# Proposal: gradeentry-learnerref-stamp

## Summary
Every `GradeEntry` gets its `learnerRef` (the LearnerProfile UUID the portal scopes on) stamped by
the server on create and update, derived from `learnerId`. Existing rows are back-filled once by a
repair step. Today no code path sets the field, so no grade reaches a pupil or parent through the
portal, whether it came from a test, an assignment, the gradebook or LTI.

## Motivation
Round 2 recon C (`_round2/recon/C-tests-grading-assignments.md`, section 1, "Cross-cutting defect")
found that both `GradeEntry` creators the recon checked omit `learnerRef`:

- `lib/Listener/GradeRollupHandler.php::handleAssessmentResultGraded()` builds the entry with
  `learnerId` only (`grep -c learnerRef` returns 0).
- `src/views/MarkSubmissionView.vue` posts the entry with `learnerId` only.

Building this change found the gap is wider. Six more creators write a `GradeEntry` without
`learnerRef`: `CohortGradebookView.vue`, `LtiAgsScorePollJob`, `ExemptionGrantHandler`,
`WerkprocesGradeEmitHandler`, `PortfolioGradeEmitHandler` and the generic create form. Every portal
collection that shows grades (`studentGrades`, `parentGrades` in
`lib/Portal/PortalContributionProvider.php`) filters on `learnerRef`. The schema's own description
calls the field "invisible to the portal (fail-closed) until backfilled", and no backfill exists.

The recon names `ReportCardComposer::resolveLearnerRef()` as the precedent to reuse. That precedent
is itself broken: it filters `learner-profile` on `learnerId`, a property LearnerProfile does not
declare (its Nextcloud user id is `ncUserId`). OpenRegister answers an unresolvable filter key with
zero rows, so the helper always returns null. This change builds one resolver that reads the right
key and uses it for every grade.

Capability rows: 10.1 (parent portal: children, grades, attendance, reports; tier NICE, partial) and
7.13 (grade notifications to learner and parents). Competitor evidence, round 1
`compare/findings.md` row 10.1: aula ("built as this parent portal: children, messages, ..."),
wilma ("grades/assessments" visible to guardians), edupage ("Grades, attendance, homework ...").
Rung 1: a property, stamped server side, no new page.

## Affected Projects
- [x] Project: `learniq` — new `LearnerRefResolver` service, new `GradeEntryLearnerRefStamp`
  listener on create and update, new `BackfillGradeEntryLearnerRef` repair step.

## Scope

### In Scope
- A `LearnerRefResolver` service: Nextcloud user id to LearnerProfile UUID, reading
  `ncUserId`, preferring a profile that is not merged away.
- A `GradeEntryLearnerRefStamp` listener on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for the
  `grade-entry` schema. The server always derives `learnerRef` from `learnerId`; a client value is
  ignored, so nobody can point a grade at another pupil's parents.
- A `BackfillGradeEntryLearnerRef` repair step (post-migration) that stamps every existing row
  that has a `learnerId` and no `learnerRef`. Idempotent.
- PHPUnit tests for all three, with a fake that behaves like OpenRegister: it reads the schema from
  `filters.schema` and answers an undeclared filter key with zero rows.

### Out of Scope
- `ReportCardComposer::resolveLearnerRef()` and `GradeRollupHandler::fanOutParentNotifications()`
  share the wrong `learnerId` filter on LearnerProfile. Both are reported in the PR as inherited;
  report cards are a separate schema and deserve their own change.
- The learniq-wide `findAll()` config shape (`register`/`schema` at the top level instead of under
  `filters`, about 170 call sites). Reported, not fixed here.
- Client-side edits to `MarkSubmissionView.vue`: the server stamp covers it.
- Portal file upload and portal test taking (D15: portaliq lane).

## Approach
One stamp on the write path instead of eight patches on eight creators. The listener follows the
`AssessmentResultAudience` posture: the server overwrites what the client sent, and a failed lookup
leaves the grade invisible to the portal rather than blocking the write. On update, a failed lookup
keeps the stored value so a transient error never hides a grade that was visible.

## New Dependencies
None.

## Impact
- New: `lib/Service/LearnerRefResolver.php`, `lib/Listener/GradeEntryLearnerRefStamp.php`,
  `lib/Repair/BackfillGradeEntryLearnerRef.php`, three test classes.
- Changed: `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php` (two registrations),
  `appinfo/info.xml` (one repair step).
- No schema change, no API change, no UI change.

## Cross-Project Dependencies
None. portaliq already reads `learnerRef` through learniq's contribution provider; once rows carry
it, grades appear without a portaliq change.

## Risks

### Risk 1: Grades become visible to guardians that were hidden before
**Severity:** Medium — **Mitigation:** that is the intended effect, and every portal collection
still filters on the guardian relation and `lifecycle`/`visibleFrom`. Concept grades stay hidden.
The stamp only ever names the profile of the grade's own `learnerId`.

### Risk 2: Duplicate LearnerProfiles for one user
**Severity:** Low — **Mitigation:** the resolver prefers a profile with an empty `mergedInto`, the
survivor of a learner merge, which matches what `LearnerMergeService` writes.

### Risk 3: Backfill cost on a large install
**Severity:** Low — **Mitigation:** paged reads of 200, a per-run cache from user id to profile,
and rows that already carry a `learnerRef` are skipped without a lookup.

## Rollback Strategy
Revert the PR. Stamped `learnerRef` values stay on the rows and are correct; nothing reads them
except the portal collections that already expect them.

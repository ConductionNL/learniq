---
kind: code
depends_on: [competency-framework]
---

# Proposal: grading-rollup-followups

## Summary

Two follow-ups on the grading roll-ups, one change because both sit in the roll-up listeners. The final grade roll-up never wrote `FinalGrade.programmeId`, so the programme page's final-grade count (its only reader, which filters on `programmeId`) always showed zero. And `CompetencyAttainmentRollupHandler` did its reads and writes inside the save that fired it, which hydra gate 61 refuses: it now queues the work for a background job.

## Motivation

TRACKER-R2 "ROUND 3 FINAL": "FinalGrade.programmeId never written" and "CompetencyAttainmentRollupHandler deferral (gate 61)". Gate 61 (`check_listener_placement.py --all`) reports: "performs a write (saveObject) inside another object's write; runs an UNBOUNDED findAll() on the write path ... and it neither defers via ListenerDeferralService nor carries `@listener-placement inline`".

## Affected Projects

- [ ] Project: `learniq`: `GradeRollupHandler`, `CompetencyAttainmentRollupHandler`, new `CompetencyAttainmentRollup` service and `CompetencyAttainmentRollupJob`, their tests.

## Scope

### In Scope

- The roll-up writes the id of the Programme whose `curriculumPlanId` is the grade's plan; a plan no programme uses keeps what the row had (null for a course-level grade).
- `CompetencyAttainmentRollupHandler` queues three kinds of work (werkproces created, grade entry published, werkproces confirmed) through `ListenerDeferralService`, deduplicated per kind and object. The work moves unchanged into `CompetencyAttainmentRollup`, run by `CompetencyAttainmentRollupJob` as the actor.

### Out of Scope

- `LessonProgressHandler` and `XapiCompletionHandler`, which gate 61 also names: separate listeners, not in this follow-up.
- Back-filling `programmeId` on existing FinalGrade rows: the next recompute of each row writes it.

## Approach

Mirror `EnrolmentProgressRollupHandler` and `EnrolmentProgressRollupJob`, the deferral the app already uses.

## New Dependencies

None.

## Impact

A new WerkprocesAssessment's `competencyId`, and a learner's CompetencyAttainment, appear once the background job has run instead of within the same request. Nothing reads either back in that request.

## Cross-Project Dependencies

OpenRegister's `ListenerDeferralService` and `ActorForwardedJob`, already used by learniq.

## Risks

### Risk 1: A page shows the attainment before the job ran

**Severity:** Low. **Mitigation:** The job runs on the next cron tick; the page shows the value on the next load.

## Rollback Strategy

Revert the merge commit.

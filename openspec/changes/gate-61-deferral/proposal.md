---
kind: code
depends_on: [grading-rollup-followups]
---

# Proposal: gate-61-deferral

## Summary

`LessonProgressHandler` and `XapiCompletionHandler` read lessons and enrolments, and write a LessonCompletion or run the enrolment's `complete` transition, inside the save of the xAPI statement that fired them. Hydra gate 61 (ADR-078: post-`*ed` work is async by default) flags both: an unbounded `findAll()` on the write path, and a write inside another object's write. As #1208 did for the competency roll-up, both handlers now only check the statement is a completed or passed xAPI statement in the learniq register and queue it through OpenRegister's `ListenerDeferralService`; `XapiStatementFollowUpJob` runs the work as the learner who sent the statement.

## Motivation

Follow-up from lane f-quality and TRACKER-R2 "ROUND 3 FINAL" (gate 61 deferral). Every xAPI statement a learner's player sends paid for these reads before its save returned.

## Scope

### In Scope

- `LessonProgress` holds the LessonCompletion upsert, moved unchanged from `LessonProgressHandler`. The completion time is stamped when the statement is queued, so a late job run does not move it.
- `XapiEnrolmentCompletion` holds the enrolment completion, moved unchanged from `XapiCompletionHandler`, including the `verified_actor_id` trust boundary and the enrolment learner re-check.
- `XapiStatementFollowUpJob` runs each queued entry; one failing entry does not stop the next. Deduplicated per kind and statement.
- The completion verb list lives once, on `XapiEnrolmentCompletion::COMPLETION_VERBS`.

### Out of Scope

- Any change to what the work does.

## Risks

### Risk 1: A completion shows a moment later
**Severity:** Low. Nothing reads the LessonCompletion or the completed enrolment back in the request that saved the statement; the progress page reads it on its next load.

## Rollback Strategy

Revert the merge commit.

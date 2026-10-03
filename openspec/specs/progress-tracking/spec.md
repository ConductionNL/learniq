# progress-tracking Specification

## Purpose
TBD - created by archiving change learning-progress-and-analytics. Update Purpose after archive.

## Requirements

### Requirement: Persist LessonCompletion domain objects in OpenRegister

The system MUST persist `LessonCompletion` as an OpenRegister object — one row per `(learnerId, lessonId)` —
carrying `learnerId`, `learnerRef` (nullable), `lessonId` (`$ref Lesson`), `courseId` (`$ref Course`,
denormalized), `enrolmentId` (nullable `$ref Enrolment`), `source` (`xapi | manual`), `verb` (nullable),
`score` (nullable), `completedAt`, and `tenant_id`. It carries no `x-openregister-lifecycle` — a completion
fact has no workflow states. `x-property-rbac.read` MUST scope to the learner themself, admin, and the
course's teacher(s); it MUST NOT be broadly readable.

#### Scenario: LessonCompletion objects persist in OpenRegister
<!-- @e2e exclude Pure OpenRegister schema/RBAC registration; verified by PHPUnit schema-validation tests and by reasoning over the register JSON (no scholiq DOM surface to drive registration itself). -->

- **GIVEN** the `progress-tracking` schemas are registered in OpenRegister
- **WHEN** a `LessonCompletion` is created for a `(learnerId, lessonId)` pair
- **THEN** it is stored as an OpenRegister object with no lifecycle field
- **AND** a learner who is not the row's own `learnerId`, an admin, or the course's teacher cannot read it

### Requirement: A lesson completion belongs to one enrolment
A `LessonCompletion` SHALL belong to the enrolment it was made in: one row per (`enrolmentId`, `lessonId`).
`LessonProgressHandler` SHALL resolve the learner's active, otherwise pending, Enrolment for the course and
update only that enrolment's row; a completion in a later enrolment (a retake, a re-enrolment, a
recertification) SHALL add a new row and leave the earlier row and its `enrolmentId` untouched. Progress for
an Enrolment SHALL count only its own completions: rows tied to it, plus untied rows completed after it was
created, each lesson once. `LessonPlayer` SHALL judge "already completed" against the learner's current
Enrolment and send its id with a manual completion. An update of a `LessonCompletion` SHALL trigger the
progress roll-up just as a create does.

#### Scenario: A retake starts with no lessons completed
- **GIVEN** a learner completed every lesson of a course in an earlier enrolment
- **WHEN** they are enrolled in the course again
- **THEN** the new enrolment's progress is 0 and the lessons show as not completed in the player
- **AND** the earlier enrolment's completions are still on record with their own `enrolmentId`

<!-- @e2e exclude Asserted in tests/Unit/Listener/LessonProgressHandlerTest.php
     (testARetakeAddsACompletionForTheNewEnrolmentAndKeepsTheOldRow),
     tests/Unit/Progress/EnrolmentProgressEvaluatorTest.php (testARetakeCountsOnlyThisEnrolmentsCompletions)
     and tests/unit-js/lessonCompletion.test.mjs; a live run needs two enrolments a year apart. -->

### Requirement: xAPI completion statements are wired into per-lesson completion, not duplicated

The system MUST derive `LessonCompletion` from the same `ObjectCreatedEvent<XapiStatement>` that `Xapi
CompletionHandler` already consumes, via a new sibling listener (`OCA\Scholiq\Listener\LessonProgress
Handler`) — reusing `XapiCompletionHandler`'s existing `verified_actor_id` trust boundary and completion-verb
list rather than re-implementing them. Unlike `XapiCompletionHandler`, this listener MUST NOT gate on
`Lesson.mandatoryTraining` or on being the highest-`order` published `Lesson` of its `Course` — every
resolvable `completed`/`passed` xAPI statement for a `Lesson` MUST produce or update a `LessonCompletion` row,
independent of whether that lesson also happens to trigger an `Enrolment` completion. `XapiCompletionHandler`
itself MUST NOT be modified by this change.

#### Scenario: A non-final, non-mandatory lesson's completion statement is recorded
<!-- @e2e exclude Backend event-listener behaviour verified by PHPUnit (LessonProgressHandlerTest: asserts a LessonCompletion is created for a statement XapiCompletionHandler itself would ignore); no DOM surface for an xAPI ObjectCreatedEvent firing server-side. -->

- **GIVEN** a `Course` with 10 published `Lesson`s, none marked `mandatoryTraining`
- **AND** a learner has an active `Enrolment` in that course
- **WHEN** an `XapiStatement` with verb `completed` is received for lesson 3 of 10
- **THEN** a `LessonCompletion` row is created for `(learner, lesson 3)` with `source: xapi`
- **AND** the learner's `Enrolment.lifecycle` does NOT transition (only the final lesson does that, per
  `XapiCompletionHandler`'s unmodified guards)

#### Scenario: A duplicate completion statement for the same lesson updates, not duplicates
<!-- @e2e exclude Backend idempotency, covered by LessonProgressHandlerTest. -->

- **GIVEN** a learner already has a `LessonCompletion` for a given `Lesson`
- **WHEN** a second `completed`/`passed` `XapiStatement` for the same learner and lesson is received
- **THEN** the existing `LessonCompletion` row is updated (`completedAt` refreshed), not duplicated

### Requirement: Learners can self-report completion of non-xAPI content

The system MUST allow a learner to create their own `LessonCompletion` (`source: manual`) for a `Lesson`
whose `contentType` does not emit xAPI statements (`text`, `video` without an embedded xAPI player, `quiz`
without cmi5 wrapping), mirroring `AssessmentResult`'s unrestricted self-serve create posture rather than the
stricter `xapi-statement` admin-only stopgap — a self-reported progress marker carries no grade or
credentialing weight. The frontend MUST expose a "Mark lesson complete" action on the `Lesson` player for
these content types.

#### Scenario: Learner marks a text lesson complete
- **GIVEN** a learner viewing a published `Lesson` with `contentType: text` and no existing `LessonCompletion`
- **WHEN** they select "Mark lesson complete"
- **THEN** a `LessonCompletion` (`source: manual`) is created for that learner and lesson
- **AND** the action becomes disabled/shows "Completed" on the same view
- **@e2e** tests/e2e/spec-coverage/progress-tracking.spec.ts

#### Scenario: Manual completion is not available for xAPI-instrumented content
- **GIVEN** a learner viewing a published `Lesson` with `contentType: cmi5`
- **WHEN** the lesson player renders
- **THEN** no "Mark lesson complete" action is shown — completion is expected to arrive via the xAPI
  statement the cmi5 launch itself emits
- **@e2e** tests/e2e/spec-coverage/progress-tracking.spec.ts

### Requirement: The follow-up of an xAPI statement runs outside the save that records it

When a completed or passed xAPI statement is saved in the learniq register, the LessonCompletion upsert and the enrolment completion MUST NOT read or write inside that save. The listeners MUST only queue the statement for a background job that runs as the actor who saved it, deduplicated per kind and statement, and the job MUST do the same work the listeners did before, with the completion time taken when the statement was queued.

#### Scenario: A completion statement is queued, not processed inline
@e2e exclude Background deferral with no UI; pinned by tests/Unit/Listener/LessonProgressHandlerTest.php::testTheHandlerQueuesTheWorkAndWritesNothingItself.
- **GIVEN** a completed xAPI statement for a lesson
- **WHEN** it is saved
- **THEN** no object is read or written in that save
- **AND** one entry is queued for XapiStatementFollowUpJob

#### Scenario: The job completes the enrolment on the final mandatory lesson
@e2e exclude Background deferral with no UI; pinned by tests/Unit/Lifecycle/XapiCompletionHandlerTest.php::testTheJobCompletesTheEnrolmentOnTheFinalMandatoryLesson.
- **GIVEN** a queued completion statement for the last published mandatory lesson of a course, and the learner's active enrolment
- **WHEN** the job runs
- **THEN** the enrolment's `complete` transition runs

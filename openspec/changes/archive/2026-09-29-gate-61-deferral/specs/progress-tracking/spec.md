# Progress tracking: xAPI follow-up outside the save delta

## ADDED Requirements

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

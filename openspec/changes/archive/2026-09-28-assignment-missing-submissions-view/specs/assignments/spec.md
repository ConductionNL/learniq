# assignments Specification

## ADDED Requirements

### Requirement: A teacher sees who has not handed in an assignment

`AssignmentDetail` MUST show staff a hand-in status section computed from the assignment's roster
and its submissions. The roster MUST be `Cohort.learnerIds` of `Assignment.cohortId`, or the union
of `learnerIds` of every cohort of `Assignment.courseId` when no cohort is set. A learner MUST count
as handed in when a submission for the assignment lists them in `learnerIds` with lifecycle
`submitted`, `late` or `returned`; as started when their only submissions are `draft`; and as not
started otherwise. The section MUST show the handed-in count out of the roster size, then every
learner who has not handed in, and MUST mark them overdue once `Assignment.dueAt` has passed. The
section MUST render nothing for a user without the `teacher` or `admin` dashboard view.

#### Scenario: Six of twenty-four have not handed in

<!-- @e2e exclude Pure diff logic covered by node test tests/unit-js/handInStatus.test.mjs; the section itself renders from that output and lanes do not run against the shared instance. -->

- **GIVEN** an assignment for a cohort of 24 learners, with 18 submissions in `submitted`, `late`
  or `returned`, 2 in `draft`, and 4 learners without a submission
- **WHEN** a teacher opens the assignment
- **THEN** the section shows "18 of 24 handed in", lists 2 learners as started and 4 as not started

#### Scenario: A course-wide assignment uses every cohort of the course

<!-- @e2e exclude Node test tests/unit-js/handInStatus.test.mjs. -->

- **GIVEN** an assignment with no `cohortId` for a course with two cohorts that share one learner
- **WHEN** the roster is built
- **THEN** it holds every learner of both cohorts once

#### Scenario: Missing work is overdue after the due date

<!-- @e2e exclude Node test tests/unit-js/handInStatus.test.mjs. -->

- **GIVEN** an assignment whose `dueAt` has passed and a learner without a submission
- **WHEN** the section is computed
- **THEN** that learner is marked overdue

#### Scenario: A pupil does not see the roster

<!-- @e2e exclude Staff gate covered by node test tests/unit-js/handInStatus.test.mjs (canSeeHandInStatus). -->

- **GIVEN** a user whose dashboard views are only `student`
- **WHEN** they open the assignment
- **THEN** the hand-in status section renders nothing

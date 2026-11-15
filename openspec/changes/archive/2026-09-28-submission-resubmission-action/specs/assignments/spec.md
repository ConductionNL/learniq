# assignments Specification

## ADDED Requirements

### Requirement: A teacher can ask for returned work to be handed in again

`SubmissionDetail` MUST offer staff an "Ask to hand in again" action on a `returned` Submission. The
action MUST fire the `reopen` transition (`returned` to `draft`) and MUST collect
`resubmissionDueAt` as a required transition input. The `reopen` transition MUST be authorized for
the `instructors`, `compliance-officers` and `team-leads` groups only. When `reopen` fires, the
Submission's learners MUST receive a `resubmissionRequested` notification.

#### Scenario: A teacher reopens returned work with a new date

<!-- @e2e exclude Declarative manifest action plus register transition; covered by PHPUnit SubmissionResubmissionRegisterTest, and lanes do not run against the shared instance. -->

- **GIVEN** a Submission in `returned`
- **WHEN** a teacher chooses "Ask to hand in again" and enters a date
- **THEN** the Submission moves to `draft` with `resubmissionDueAt` set to that date, and its
  learners are notified

#### Scenario: A pupil cannot reopen their own work

<!-- @e2e exclude Register-level authorization; covered by PHPUnit SubmissionResubmissionRegisterTest. -->

- **GIVEN** a Submission in `returned`
- **WHEN** one of its learners, outside the staff groups, fires `reopen`
- **THEN** the transition is refused

### Requirement: A requested resubmission has its own deadline

`SubmissionWindowGuard` MUST judge `submit` and `submitLate` against `Submission.resubmissionDueAt`
when it is set, instead of `Assignment.dueAt`. Before that date `submit` MUST be allowed and
`submitLate` refused; after it the existing late rules MUST apply with that date as the deadline.

#### Scenario: Resubmission after the assignment deadline is on time

<!-- @e2e exclude Lifecycle guard; covered by PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** an assignment whose `dueAt` has passed and that does not accept late work, and a
  reopened Submission with `resubmissionDueAt` in the future
- **WHEN** the learner fires `submit`
- **THEN** the guard allows it and no late penalty applies

#### Scenario: The resubmission date has passed

<!-- @e2e exclude PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** a reopened Submission whose `resubmissionDueAt` has passed, on an assignment that accepts
  late work
- **WHEN** the learner fires `submit`
- **THEN** the guard refuses it, and `submitLate` is allowed

#### Scenario: Late hand-in is refused while the resubmission window is open

<!-- @e2e exclude PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** a reopened Submission with `resubmissionDueAt` in the future
- **WHEN** the learner fires `submitLate`
- **THEN** the guard refuses it

### Requirement: Only staff set a resubmission date

`SubmissionResubmissionDateListener` MUST keep `Submission.resubmissionDueAt` out of the hands of
learners: on create by a caller outside `instructors`, `compliance-officers`, `team-leads` and
admins the value MUST be dropped, and on update by such a caller the stored value MUST be kept.
Staff, admins and system context MUST be able to write it.

#### Scenario: A learner cannot give themselves a later date

<!-- @e2e exclude Pre-write listener; covered by PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a learner's own Submission in `draft` with `resubmissionDueAt` set by their teacher
- **WHEN** the learner updates it with a later `resubmissionDueAt`
- **THEN** the stored date is kept

#### Scenario: A learner cannot create work with a date of their own

<!-- @e2e exclude PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a learner outside the staff groups
- **WHEN** they create a Submission carrying `resubmissionDueAt`
- **THEN** the Submission is stored without it

#### Scenario: A teacher's reopen writes the date

<!-- @e2e exclude PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a teacher in `instructors`
- **WHEN** the reopen transition writes `resubmissionDueAt`
- **THEN** the date is stored


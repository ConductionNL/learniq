## ADDED Requirements

### Requirement: A mandatory enrolment shows its deadline to the learner

An `Enrolment` MUST be able to carry a `dueDate`. The learner's own course list MUST show that date and the number of days left, from the materialised `daysRemaining` calculation.

#### Scenario: The learner reads the deadline of a mandatory course

- **GIVEN** an active enrolment for "Werken op hoogte" with `dueDate` 30 days from today
- **WHEN** the learner opens "Mijn leren"
- **THEN** the course shows its due date and 30 days left

#### Scenario: An enrolment without a deadline shows none

- **GIVEN** an active enrolment with no `dueDate`
- **WHEN** the enrolment is read
- **THEN** `daysRemaining` is null and no due date is shown

### Requirement: A learner sees which of their courses is late

An `Enrolment` MUST compute `isOverdue` (active, with a `dueDate` in the past) and `ragStatus`: `completed` for a completed enrolment, `red` when overdue, `amber` when seven days or fewer are left, and `green` otherwise. The learner's course list MUST show that state per course.

#### Scenario: A course past its deadline reads as late

- **GIVEN** an active enrolment whose `dueDate` was yesterday
- **WHEN** the learner opens "Mijn leren"
- **THEN** the course is marked late and its `ragStatus` is `red`

#### Scenario: A course close to its deadline reads amber

- **GIVEN** an active enrolment with five days left
- **WHEN** the enrolment is read
- **THEN** `isOverdue` is false and `ragStatus` is `amber`

### Requirement: Staff enrol one learner in a course

Staff who may create enrolments MUST be able to enrol one learner in one course from the enrolments page or the learner's page. The new `Enrolment` MUST name the learner and the course and start in `pending`.

#### Scenario: A team lead enrols one employee

- **GIVEN** a published course and a learner with no enrolment for it
- **WHEN** a team lead creates an enrolment for that learner and course
- **THEN** an `Enrolment` in `pending` exists naming the learner and the course
- **AND** the learner's page lists the course

### Requirement: Applications are decided from the admissions review board

The admissions review board MUST let a coordinator decide each pending application: place it, put it on the waiting list, or reject it. Each decision MUST run as a lifecycle transition through `AdmissionsDecisionGuard`, so the capacity and reason rules hold whoever decides.

#### Scenario: A coordinator places an applicant from the board

- **GIVEN** an application in `intake-completed` in a round with free places
- **WHEN** the coordinator chooses to place it on the review board
- **THEN** the application moves to `placed`

#### Scenario: A rejection needs a reason

- **GIVEN** an application on the review board
- **WHEN** the coordinator rejects it without a reason
- **THEN** the rejection is refused

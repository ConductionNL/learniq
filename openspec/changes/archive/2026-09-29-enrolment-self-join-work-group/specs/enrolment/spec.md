# enrolment Specification

## ADDED Requirements

### Requirement: A teacher sets up work groups with a maximum size

A user in `instructors`, `team-leads` or `compliance-officers` MUST be able to create work groups for a cohort, grouped in a named set, each with a name and a maximum number of members, and MUST be able to open them for self sign-up until a date, move a member, and close sign-up. Learners MUST NOT be able to create or edit a `WorkGroup` through the object API.

#### Scenario: A teacher makes five groups of four

- **GIVEN** a teacher on the cohort page of "MV2A marketing en communicatie"
- **WHEN** the teacher opens "Work groups", adds a set "Project campagne periode 2" with five groups of four, open for sign-up until 14 November, and saves
- **THEN** the tab shows five groups, each with four free places and the sign-up date

### Requirement: A learner joins a work group with a free place

A learner of the cohort MUST be able to see the cohort's open work groups with their free places and join a group that has a free place while sign-up is open. A group at its maximum MUST refuse a join with a reason, also when two learners try for the last place at the same moment. After the sign-up date a learner MUST NOT be able to join, move or leave.

#### Scenario: A learner joins a group

- **GIVEN** learner s.dekker in "MV2A marketing en communicatie" and "Groep 4" with two of four places taken
- **WHEN** s.dekker opens "My work groups" and chooses "Join" on "Groep 4"
- **THEN** s.dekker is listed as a member of "Groep 4"
- **AND** "Groep 4" shows one free place

#### Scenario: A full group takes nobody more

<!-- @e2e exclude Race and cap rule in the service; covered by WorkGroupMembershipServiceTest::testLastPlaceGoesToOneLearnerOnly. -->

- **GIVEN** "Groep 1" with four of four places taken
- **WHEN** a learner of the cohort posts to `POST /api/work-groups/{id}/join`
- **THEN** the answer says the group is full and the group still has four members

### Requirement: A learner is in one work group per set

A learner MUST be a member of at most one work group per set of a cohort. Joining another group of the same set while sign-up is open MUST move the learner in one step.

#### Scenario: A learner switches groups

- **GIVEN** s.dekker is in "Groep 4" and "Groep 5" has a free place
- **WHEN** s.dekker chooses "Move here" on "Groep 5"
- **THEN** s.dekker is a member of "Groep 5" only

### Requirement: A group hand-in names the whole work group

When an assignment has `groupSubmission` and names a work group set, the hand-in screen MUST list every member of the learner's work group in that set as the submission's learners.

#### Scenario: One member hands in for the group

- **GIVEN** the assignment "Campagneplan" set to group hand-in for the set "Project campagne periode 2", and s.dekker in "Groep 5" with three others
- **WHEN** s.dekker hands in the plan on the hand-in screen
- **THEN** the submission lists all four members of "Groep 5"
- **AND** each of them sees the hand-in on their own assignment page

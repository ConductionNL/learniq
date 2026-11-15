# enrolment Specification

## ADDED Requirements

### Requirement: A course or programme says whether learners may sign up

`Course` and `Programme` MUST declare `selfEnrolment` with the values `closed`, `open` and `on-request`, default `closed`. Only a `published` course or programme whose `selfEnrolment` is not `closed` MUST appear in the learner catalogue. Every course and programme stored before this change MUST read as `closed`.

#### Scenario: A training coordinator opens a course for sign-up

- **GIVEN** the published course "Excel voor gevorderden"
- **WHEN** a training coordinator sets sign-up to open on the course form and saves
- **THEN** the course appears in the learner catalogue

### Requirement: A learner signs up from the catalogue

A signed-in learner MUST be able to browse and search the catalogue and sign up for a course that is open or on request. Signing up MUST create one `Enrolment` for the caller only, with `source: self`: active at once for an open course, pending for a course on request. A prerequisite that is not met MUST refuse the sign-up with the message of the prerequisite check. A closed or unpublished course MUST be refused.

#### Scenario: A learner signs up for an open course

- **GIVEN** learner p.ganpat and the open course "Excel voor gevorderden"
- **WHEN** p.ganpat opens the catalogue, searches "Excel", opens the course and chooses "Sign up"
- **THEN** the course shows "You are signed up"
- **AND** p.ganpat has an active enrolment with source self

#### Scenario: A prerequisite blocks a sign-up

<!-- @e2e exclude Veto from EnrolmentPrerequisiteListener through the service; covered by CatalogueSignUpServiceTest::testPrerequisiteVetoIsReturned. -->

- **GIVEN** a course that requires "Excel basis", which the learner has not completed
- **WHEN** the learner posts to `POST /api/catalogue/courses/{id}/sign-up`
- **THEN** the answer names "Excel basis" as the missing prerequisite and no enrolment is created

### Requirement: A learner signs up for a whole programme

Signing up for an open or on-request programme MUST create one enrolment per course of the programme that the learner is not already enrolled in, each with `programmeId` set, without bypassing the prerequisite check.

#### Scenario: A learner signs up for a track

- **GIVEN** the open programme "Basis projectmanagement" with three courses
- **WHEN** a learner chooses "Sign up" on the programme in the catalogue
- **THEN** the learner has three enrolments, one per course, each naming the programme

### Requirement: A request waits for a teacher or manager

A sign-up for a course on request MUST stay pending until a user in `instructors`, `hr` or `team-leads`, or the learner's manager, approves it with `activate` or declines it with a reason. The learner MUST be told either way.

#### Scenario: A manager approves a request

- **GIVEN** a pending sign-up by p.ganpat for "Leidinggeven aan hybride teams", with p.ganpat's manager as `managerId`
- **WHEN** the manager opens "Sign-up requests" and chooses "Approve"
- **THEN** the enrolment is active and p.ganpat gets a notification that the sign-up was approved

### Requirement: A learner withdraws their own sign-up

A learner MUST be able to withdraw an enrolment they signed up for themselves while it is pending or active with no progress. An enrolment made by staff, or one with progress, MUST NOT be withdrawn by the learner.

#### Scenario: A learner changes their mind

- **GIVEN** p.ganpat's active self sign-up for "Excel voor gevorderden" with no progress
- **WHEN** p.ganpat chooses "Withdraw" on the course in the catalogue
- **THEN** the enrolment is withdrawn and the course shows "Sign up" again

### Requirement: Provider courses show their provider

A course written by an outside provider's catalogue connector MUST show the provider's name on its catalogue card and MUST be filterable by provider. Staff MUST be able to list imported courses that are not yet published, to choose which ones the school offers.

#### Scenario: An administrator publishes a provider course

- **GIVEN** twenty draft courses written by the Go1 connector with `author` "Go1"
- **WHEN** an administrator opens the course list with the filter "Imported, not yet published", opens one and publishes it with sign-up open
- **THEN** that course appears in the learner catalogue with "Go1" as its provider
- **AND** the other nineteen do not appear

### Requirement: The learner is told in words that fit a chosen course

When a self sign-up becomes active, the learner MUST get a notification that says they are signed up for the course. The notification for a mandatory enrolment MUST NOT be sent for a self sign-up.

#### Scenario: No mandatory-course message for a chosen course

<!-- @e2e exclude Notification condition in the register dialect; covered by CatalogueSignUpRegisterTest and gate 18. -->

- **GIVEN** a learner signs up for an open course
- **WHEN** the enrolment is activated
- **THEN** the learner gets "You are signed up for Excel voor gevorderden"
- **AND** not "You have been enrolled in a mandatory course"

# Assignments: submission learnerRefs delta

## ADDED Requirements

### Requirement: Every Submission carries server-stamped learnerRefs

Every Submission MUST carry `learnerRefs`: the LearnerProfile UUID of each learner in `learnerIds` who has a profile, found on `ncUserId`. The server MUST derive the list on every create and update and MUST ignore a `learnerRefs` value sent by the client. A learner without a profile MUST add no entry. The stamp MUST NOT block the write: when a lookup fails on create the list is empty, and on an update that keeps the same learners the stored list is kept.

#### Scenario: A pupil's upload reaches the portal

- **GIVEN** pupil `leerling-001` with LearnerProfile `lp-001`
- **WHEN** a Submission is created with `learnerIds: ["leerling-001"]`
- **THEN** it is stored with `learnerRefs: ["lp-001"]`
- **AND** the portal's student submissions collection shows it to that pupil

#### Scenario: A group submission names every member with a profile

- **GIVEN** pupils `leerling-001` and `leerling-002` with profiles, and `leerling-099` without one
- **WHEN** a Submission is created with all three in `learnerIds`
- **THEN** `learnerRefs` holds the two profile UUIDs

#### Scenario: A forged learnerRefs is replaced

- **GIVEN** a client sends `learnerRefs: ["lp-002"]` with `learnerIds: ["leerling-001"]`
- **WHEN** the Submission is created
- **THEN** it is stored with `learnerRefs: ["lp-001"]`

#### Scenario: A failed lookup never blocks the upload

- **GIVEN** the profile lookup fails
- **WHEN** a Submission is created
- **THEN** the write goes through with `learnerRefs: []`

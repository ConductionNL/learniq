## ADDED Requirements

### Requirement: Reading a learner's record is logged, not only changing it

Every schema that holds sensitive learner data MUST declare `x-openregister-processing.logReads: true`, so that OpenRegister writes an audit entry for each read. This MUST hold at least for `LearnerProfile`, `DossierNote`, `BehaviourIncident` and `AttendanceRecord`.

#### Scenario: A read of a dossier note is logged

- **GIVEN** a dossier note about a learner
- **WHEN** a mentor opens it
- **THEN** the audit trail holds a read entry naming the mentor and the note

### Requirement: Each processing purpose carries a review date

A schema's processing block MUST be able to declare `reviewIntervalMonths` and `nextReviewAt`, so that every processing purpose learniq registers names when it is next reviewed.

#### Scenario: A purpose with a yearly review

- **GIVEN** a schema whose processing block declares `reviewIntervalMonths: 12` and a `nextReviewAt`
- **WHEN** learniq registers that processing purpose
- **THEN** the registered purpose carries the review interval and the next review date

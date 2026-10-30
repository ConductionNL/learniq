## ADDED Requirements

### Requirement: The primary school set has a record for every drawn detail page

The po example set MUST contain an aanmelding in an open admissions round, an issued certificate of one of its pupils, an assessment item in an item bank, a peer review by a pupil other than the one who handed in, and a lesson delivered as cmi5, each with a fixed uuid in the set's namespace.

#### Scenario: The certificate page opens

- **GIVEN** the po set is loaded
- **WHEN** a user opens `/credentials/ee010025-0000-4000-8000-000000000001`
- **THEN** the page shows the verkeersdiploma of a groep 8 pupil

#### Scenario: The peer review page opens

- **GIVEN** the po set is loaded
- **WHEN** a teacher opens the peer review `ee010028-0000-4000-8000-000000000001` of its assignment
- **THEN** the review names a reviewer who is not the pupil who handed in

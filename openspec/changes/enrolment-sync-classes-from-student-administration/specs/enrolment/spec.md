## ADDED Requirements

### Requirement: Classes from the student administration land as cohorts

When integriq hands learniq `class-roster` records, the system MUST upsert one `Cohort` per class, matched on `externalId` and `externalSource`, with its name, school year, programme year, `learnerIds` and `teacherIds` as sent. It MUST stamp `syncedAt`. A record without `externalId` or with an unknown pupil MUST be refused with a code and the field name, never the value.

#### Scenario: A new class arrives

- **GIVEN** no cohort with external id `mag-3hvb-2026`
- **WHEN** integriq hands over class 3HV-B with that id and 27 pupils
- **THEN** a cohort 3HV-B exists with those 27 learners and `externalSource: magister`

#### Scenario: A pupil learniq does not know

- **GIVEN** a class record with a member whose ECK iD matches no learner profile
- **WHEN** the record is landed
- **THEN** the record is refused with `ROSTER-UNKNOWN-PUPIL` and field `learnerIds`

### Requirement: Joining and leaving a class follow the source

When a synced cohort's members change, the system MUST give each new member the cohort's enrolments with source `roster-sync`, and MUST move each leaver's open enrolments for that cohort to `withdrawn` with the reason "Left the class in the student administration". It MUST NOT delete an enrolment.

#### Scenario: A pupil joins in November

- **GIVEN** synced cohort 3HV-B with a course enrolment for every member
- **WHEN** the next sync adds Daan to 3HV-B
- **THEN** Daan has an active enrolment for that course with source `roster-sync`

#### Scenario: A pupil moves to another class

- **GIVEN** Yara is a member of 3HV-B with an active enrolment for its course
- **WHEN** the next sync moves her to 3HV-C
- **THEN** her 3HV-B enrolment is `withdrawn` with the reason
- **AND** her grades and attendance under that enrolment stay readable

### Requirement: The source's fields are read only on a synced cohort

On a cohort with `externalSource`, the system MUST refuse a manual change to name, school year, programme year, `learnerIds` and `teacherIds`, and MUST keep notes, work groups and the report card template editable.

#### Scenario: A coordinator renames a synced class

- **GIVEN** synced cohort 3HV-B
- **WHEN** a coordinator changes its name
- **THEN** the save is refused with a message that the name comes from the student administration

#### Scenario: A teacher adds a note

- **GIVEN** synced cohort 3HV-B
- **WHEN** the mentor adds a note
- **THEN** the save succeeds

### Requirement: The groups page shows the last sync

The groups page MUST show when classes were last synced, from which source and how many changes the run made, and MUST show the reason when the last run failed.

#### Scenario: A morning sync

- **GIVEN** a sync from Magister at 07.00 that changed 3 memberships
- **WHEN** a coordinator opens Groepen
- **THEN** the page says "Bijgewerkt uit Magister om 07.00, 3 wijzigingen"

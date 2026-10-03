## ADDED Requirements

### Requirement: Elective sessions in the personal timetable

The personal timetable MUST include the sessions of every course in which the caller holds an active enrolment, in addition to the sessions of the caller's cohorts, without duplicates. The sessions MUST respect the school's timetable visibility policy.

#### Scenario: A chosen elective appears

- **GIVEN** a learner whose approved subject choice created an enrolment in Drama, whose sessions belong to another cohort
- **WHEN** the learner opens My timetable
- **THEN** the Drama sessions are listed in their time slots

#### Scenario: A withdrawn enrolment disappears

- **GIVEN** a learner who withdrew from Drama
- **WHEN** the learner opens My timetable
- **THEN** no Drama session is listed

#### Scenario: No duplicates

- **GIVEN** a course the learner attends through their cohort and also through an enrolment
- **WHEN** the learner opens My timetable
- **THEN** each session appears once

### Requirement: Clash warning when choosing

The subject choice picker MUST show the time slots of each elective and MUST warn the learner when the chosen electives overlap with each other or with the learner's core lessons.

#### Scenario: Two electives overlap

- **GIVEN** a learner choosing Drama and Robotics that meet on Tuesday at 10:00
- **WHEN** the learner selects both
- **THEN** the picker warns that they overlap and names the slot

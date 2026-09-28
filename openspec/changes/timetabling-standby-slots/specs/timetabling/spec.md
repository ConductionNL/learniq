# timetabling Specification

## ADDED Requirements

### Requirement: A coordinator plans standby hours

A user in `team-leads` or `compliance-officers` MUST be able to plan standby hours per teacher, weekly or on one date, with a time window, a location and a validity period. A teacher MUST see their own standby hours in their personal timetable.

#### Scenario: A coordinator puts a teacher on standby

- **GIVEN** a coordinator on the standby planning page for 2026-2027
- **WHEN** they add teacher e.devries to Tuesday 10:15 to 11:05 at the main location and save
- **THEN** the Tuesday cell for that hour lists e.devries
- **AND** e.devries's timetable shows a standby block every Tuesday at 10:15

### Requirement: The substitution dialog offers standby teachers first

When a coordinator or the cohort's teacher assigns a substitute, the dialog MUST list the teachers on standby during the lesson first, then the teachers who work that day and have no lesson at that time, each with the reason they are listed, and MUST allow a search over all staff. A standby teacher who has a lesson at that time MUST be listed last with that reason. The absent teacher MUST NOT be listed. Learniq MUST NOT assign a substitute on its own.

#### Scenario: A coordinator covers a sick teacher's lesson

- **GIVEN** Tuesday's 10:15 "Wiskunde B, 4 havo" whose teacher is ill, and e.devries on standby then
- **WHEN** the coordinator opens the lesson's substitution dialog
- **THEN** e.devries is at the top under "On standby"
- **AND** choosing e.devries with the reason teacher absence saves the substitution

#### Scenario: A busy standby teacher goes last

<!-- @e2e exclude Ordering rule of the candidate service; covered by SubstitutionCandidateServiceTest::testBusyStandbyTeacherGoesLast. -->

- **GIVEN** a teacher on standby on Wednesday hour 2 who also teaches a lesson then
- **WHEN** candidates are requested for another lesson at that time
- **THEN** that teacher is last, with the reason "has a lesson then"

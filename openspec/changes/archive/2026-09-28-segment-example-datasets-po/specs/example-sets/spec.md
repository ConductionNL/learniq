# Example sets Specification

## ADDED Requirements

### Requirement: The primary school set is one consistent school
`lib/Settings/profiles/po.json` MUST describe one fictional primary school through the 2025-2026 school year: one `School`, two `Vestiging` locations, seven classes named "Groep 1" to "Groep 8" with a combined "Groep 5/6", between 180 and 220 pupils each with at least one guardian profile (`roles: ["parent"]`) and one enrolment, staff with subject assignments, two report periods with report cards, LVS results, dossier notes and support requests. Every attendance mark MUST sit on a session of the pupil's own class, on a school day on or after the pupil's inschrijving, marked by the teacher whose `teacherAssignments.days` cover that weekday. No session MAY fall in a holiday or on a study day the report periods list. Every report card's `attendanceSummary` MUST count exactly the marks of its period. Every name, address, school and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: An absence belongs to the pupil's own class and teacher
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the pupil's enrolment and the class's teacher assignments
- **THEN** the session's class is the pupil's class, the day is a weekday on or after the inschrijving, and `markedBy` is the teacher on duty that weekday

#### Scenario: The report card and the attendance list agree
- **GIVEN** a pupil's report card for Rapport 1
- **WHEN** the pupil's marks between the period's start and end are counted per status
- **THEN** the counts equal the card's `absentExcusedCount`, `absentUnexcusedCount`, `lateCount` and `leftEarlyCount`

#### Scenario: The curated seed lives on in the set
- **GIVEN** the register's former `x-openregister-seed` rows (De Wilgenboom, "Groep 5/6" with its duo split, "Groep 7" notes, Herfstvakantie, the technisch lezen group plan)
- **WHEN** the set is read
- **THEN** each is present, found by name, with fictional names and codes

### Requirement: The primary school set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('po')`) MUST name every object exactly once, the last-loaded first and the school last. The file MUST equal what `scripts/example-sets/po.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `po.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Primary school" appears with an object count equal to the objects in the file

#### Scenario: Removal covers every object once
- **GIVEN** the shipped `po.json`
- **WHEN** `uuidsFor('po')` is read
- **THEN** it holds as many unique uuids as the file has objects, the last-loaded first, the school last

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/po.py --check` runs
- **THEN** it exits 0

### Requirement: The register no longer carries dark primary school seeds
The ten `x-openregister-seed` blocks promoted into the set (`School`, `Vestiging`, `Cohort`, `Enrolment`, `ReportPeriod`, `GroupPlan`, `GroupPlanSubgroup`, `GroupPlanEvaluation`, `Staff`, `SubjectTeacherAssignment`) MUST be empty, and the tests that read them MUST read the set instead, finding rows by name or uuid and asserting floors.

#### Scenario: A seed test reads the set
- **GIVEN** `SchoolAndLocationRegisterTest` and the five other repointed tests
- **WHEN** they run
- **THEN** they read `lib/Settings/profiles/po.json` and pass

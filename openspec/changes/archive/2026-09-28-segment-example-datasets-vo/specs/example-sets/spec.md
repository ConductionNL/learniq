# Example sets Specification

## ADDED Requirements

### Requirement: The secondary school set is one consistent school
`lib/Settings/profiles/vo.json` MUST describe one fictional havo/vwo school through the 2025-2026 school year: one `School` with a BRIN ending in a digit, two `Vestiging` locations, eleven classes covering leerjaar 1 to 6 (a havo/vwo brugklas, then havo 2 to 5 and vwo 2 to 6), between 250 and 350 pupils each with at least one guardian profile (`roles: ["parent"]`) and exactly one enrolment on a class of their own leerjaar, a mentor and subject teachers per class (`SubjectTeacherAssignment`), a decaan and a zorgcoördinator on `Staff`, and three report periods with a report card per pupil per period. Every attendance mark MUST sit on a session of the pupil's own class, on a school day on or after the pupil's inschrijving; an absence MUST be marked by the attendance desk and a late arrival by a teacher of that class who works that weekday. No session MAY fall in a holiday or on a study day the report periods list, and no exam class session MAY fall after its last lesson day. Every report card's `attendanceSummary` MUST count exactly the marks of its period. Every name, address, school and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: A late arrival belongs to the pupil's own class and a teacher on duty
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the pupil's enrolment, the class's teachers and the staff working days
- **THEN** the session's class is the pupil's class, the day is a weekday on or after the inschrijving, and a `late` mark is marked by a teacher of that class whose `workingDays` include that weekday

#### Scenario: The report card and the attendance list agree
- **GIVEN** a pupil's report card for periode 2
- **WHEN** the pupil's marks between the period's start and end are counted per status
- **THEN** the counts equal the card's `absentExcusedCount`, `absentUnexcusedCount`, `lateCount` and `leftEarlyCount`

### Requirement: Grades agree with the records derived from them
Every `GradeEntry` MUST score a component its `CurriculumPlan` declares, in the period that component names, on a session of the pupil's own class that falls on a toetsweek day. Every `FinalGrade` MUST equal the weighted average of that pupil's grade entries on its plan, with a `breakdown` of per-period averages and per-component contributions shaped as `GradeAggregationEngine` computes them. Every exam class pupil MUST have a `FinalGrade` on a PTA plan (`kind: pta`) for each subject in their package. A report card subject line with a `FinalGrade` behind it MUST carry that grade's period average and list exactly the grade entries of that period.

#### Scenario: An SE final grade is the weighted average of its SE grades
- **GIVEN** a havo 5 pupil's final grade for Nederlands on the PTA plan
- **WHEN** the pupil's grade entries on that plan are averaged with the plan's component weights
- **THEN** the result equals the final grade's `value`, and `breakdown.periods` holds the average per period

#### Scenario: The report card shows the period average of the stored grades
- **GIVEN** an exam class pupil's report card for periode 1
- **WHEN** its subject line for a PTA plan is compared with the final grade and the grade entries
- **THEN** `periodAverage` equals `breakdown.periods["1"]` and `sourceGradeEntryIds` are the pupil's entries on that plan in period 1

### Requirement: The school's own records tell the secondary school story
The set MUST carry a `SubjectChoice` for every leerjaar 3 pupil against the havo or vwo package plan for 2026-2027; an approved choice MUST satisfy that plan's `electiveRules`, and a choice in `needs-revision` MUST list the validator's message for the rule it breaks. It MUST carry exactly one `SchoolAdvies`, for a brugklas pupil, whose converted `Application` points at that pupil's profile and enrolment. It MUST carry a leerplicht threshold with at least one `AttendanceFlag` whose breaching records are that pupil's unexcused absences in the window and add up to more than 16 hours, and an attendance-percentage flag whose value equals that pupil's report card percentage for the period.

#### Scenario: An approved profielkeuze satisfies the package rules
- **GIVEN** an approved leerjaar 3 subject choice
- **WHEN** its selected electives are checked against the plan's `minElectives`, `maxElectives` and `mutuallyExclusive` rules
- **THEN** no rule is broken

#### Scenario: The incoming schooladvies belongs to a brugklas pupil
- **GIVEN** the set's one `SchoolAdvies`
- **WHEN** its learner is looked up
- **THEN** the learner is enrolled in a brugklas, and a converted application names that learner's profile and enrolment

### Requirement: The secondary school set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('vo')`) MUST name every object exactly once, the last-loaded first and the school last. The file MUST equal what `scripts/example-sets/vo.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `vo.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Secondary school" appears with an object count equal to the objects in the file

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/vo.py --check` runs
- **THEN** it exits 0

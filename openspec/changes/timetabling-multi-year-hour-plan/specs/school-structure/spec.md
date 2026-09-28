# school-structure Specification

## ADDED Requirements

### Requirement: A programme has an hour plan over its whole length

Learniq MUST let a user in `instructors`, `team-leads` or `compliance-officers` keep, per programme and intake year, an hour plan that states for every course, year of the programme and period the contact hours and other hours a group must receive, and a norm per year. The plan MUST show its totals per year and MUST mark every year whose contact hours fall below that year's norm. A programme MUST have at most one active plan per intake year.

#### Scenario: A coordinator plans three years of a programme

- **GIVEN** the programme "Medewerker marketing en communicatie" of three years with six courses
- **WHEN** a coordinator opens the programme, creates an hour plan for intake 2026-2027 and fills the hours per course, year and period
- **THEN** the plan shows the contact hours of year one, two and three next to each year's norm of 700 hours

#### Scenario: A year below its norm is marked

- **GIVEN** an hour plan whose year two holds 640 contact hours and a norm of 700
- **WHEN** the coordinator opens the plan
- **THEN** year two is marked as 60 hours short of its norm

#### Scenario: A second active plan for the same intake is refused

<!-- @e2e exclude Lifecycle guard; covered by HourPlanActivationGuardTest. -->

- **GIVEN** an active hour plan for the programme and intake 2026-2027
- **WHEN** a coordinator activates a second plan for the same programme and intake
- **THEN** the activation is refused with a reason naming the active plan

### Requirement: A cohort knows which year of its programme it is in

`Cohort` MUST declare `programmeYear`. Moving a cohort up at the school year rollover MUST raise it by one.

#### Scenario: The rollover moves a group into its second year

<!-- @e2e exclude Rollover step over stored cohorts; covered by the rollover unit test. -->

- **GIVEN** cohort "MV1A" with `programmeYear` 1 in 2025-2026
- **WHEN** the school year rollover moves it up to 2026-2027
- **THEN** the new cohort has `programmeYear` 2

### Requirement: Learniq lists the teaching activities a school year needs

For a school year, learniq MUST list per cohort the hour plan lines that apply to it: the plan of the cohort's programme for the intake year the cohort started in, filtered on the cohort's programme year, with the teachers assigned to that cohort and course. The list MUST be derived on every read and MUST be available as a page with a CSV export, at `GET /api/hour-plans/activities` for staff, and through an in-process query event for another fleet app. Learniq MUST NOT place any activity in a week, a day or a room.

#### Scenario: A timetabler exports next year's activities

- **GIVEN** cohort "MV2A" in its second year and an active hour plan for its intake
- **WHEN** a timetabler opens "Teaching activities", picks 2026-2027 and exports
- **THEN** the file holds a row per course of year two for MV2A with its period, contact hours and teacher

#### Scenario: A learner cannot read the activity list

<!-- @e2e exclude Access rule on an endpoint; covered by HourPlanControllerTest::testLearnerIsRefused. -->

- **GIVEN** a user who is in no staff group
- **WHEN** they request `GET /api/hour-plans/activities?academicYear=2026-2027`
- **THEN** the request is refused

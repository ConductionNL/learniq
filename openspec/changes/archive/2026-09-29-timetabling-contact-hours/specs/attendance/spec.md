# attendance Specification

## ADDED Requirements

### Requirement: A coordinator compares owed, given and attended contact hours

For a chosen period, learniq MUST show per cohort and course the contact hours owed by the cohort's hour plan, the hours given (the duration of the cohort's sessions for that course that were not cancelled) and the difference, with a total per cohort. A course whose given hours fall below its owed hours MUST be marked with the number of hours short. A cohort without an active hour plan MUST show owed hours as missing, not as zero. The report MUST be reachable from the Reports page and exportable as CSV, and MUST be readable only by `instructors`, `team-leads` and `compliance-officers`.

#### Scenario: A coordinator finds a group short on English

- **GIVEN** cohort "MV2A" owed 60 hours of "Engels" in periods 1 and 2, and three of its English lessons were cancelled
- **WHEN** a coordinator opens Reports, chooses "Contact hours", picks periods 1 and 2 and cohort MV2A
- **THEN** the row for "Engels" shows 60 owed, 57 given and 3 hours short, marked
- **AND** the export holds the same numbers

#### Scenario: A learner cannot open the report

<!-- @e2e exclude Access rule on an endpoint; covered by ContactHoursControllerTest::testLearnerIsRefused. -->

- **GIVEN** a user in no staff group
- **WHEN** they request `GET /api/reports/contact-hours`
- **THEN** the request is refused

### Requirement: A learner who attended too little is marked

Per learner of a cohort, the report MUST show the hours attended (the `lesuren` of their records with status present, late or left-early in the period) next to the hours given to the group, and MUST mark a learner who attended less than the given hours minus a configurable margin (default 10 percent).

#### Scenario: A mentor sees a learner at seventy percent

- **GIVEN** 40 hours of "Marketing" given to MV2A in period 1, and learner r.visser attended 28 of them
- **WHEN** the mentor opens the MV2A row and the learner view
- **THEN** r.visser shows 28 of 40 hours, marked as below the margin

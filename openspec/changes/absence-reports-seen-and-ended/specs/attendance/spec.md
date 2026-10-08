## ADDED Requirements

### Requirement: A teacher's first look at an absence report is recorded

An `excuse-request` MUST record `seenBy` and `seenAt` the first time a member of staff in its `teacherIds` opens it, alone or inside the register that lists it. Later openings MUST NOT change them, and seeing MUST NOT change the lifecycle.

#### Scenario: Juf Esra opens the register
- **GIVEN** Fatima reported Sami ill at 7.40 on Monday 5 October
- **WHEN** juf Esra opens the register of groep 4 at 8.12
- **THEN** the report reads seen by juf Esra at 8.12 and is still `submitted`
- @e2e exclude spec-only proposal; the build asserts the stamp in the listener's unit tests

### Requirement: A report may cover lesson hours, and an illness may stay open until the pupil is better

An `excuse-request` MAY name `fromLessonHour` and `toLessonHour` on a single day; then only the attendance of the lessons in those hours MUST follow its decision. An illness MAY leave `dateTo` empty; it then covers each following school day until `reportRecovered` sets the last day, which MUST NOT be before `dateFrom`.

#### Scenario: Noor leaves after the fourth hour
- **GIVEN** Erik reports Noor for hours 5 to 7 on Monday 5 October
- **WHEN** the report is approved
- **THEN** only the attendance of hours 5, 6 and 7 reads absent with permission
- @e2e exclude as above

#### Scenario: Better on Wednesday
- **GIVEN** an open illness report from Monday without a last day
- **WHEN** the parent reports recovery with last day Tuesday
- **THEN** the report ends on Tuesday and Wednesday's register no longer lists it
- @e2e exclude as above

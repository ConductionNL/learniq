## ADDED Requirements

### Requirement: Absence and lateness are counted per learner per school year
The system MUST keep one `AttendanceSummary` per learner per school year with `absentDays`, `absentAuthorisedDays`, `absentUnauthorisedDays`, `lateCount`, `lateMinutes` and `updatedAt`. A school year MUST run from 1 August to 31 July. The day of a record MUST be the date of its Session's `startsAt`, or of its `markedAt` when the Session cannot be read. A date with at least one `absent-excused` or `absent-unexcused` record MUST count as one absent day; it MUST count as without permission when at least one of its absences is `absent-unexcused`, otherwise as with permission. Every `late` record MUST add one to `lateCount` and its `lateMinutes` to `lateMinutes`; a late record without `lateMinutes` MUST add its lesson length minus `minutesAttended` when both are known, and nothing otherwise. A `left-early` record MUST NOT be counted.

#### Scenario: A primary school pupil's year adds up
@e2e exclude Pure counting rule; covered by tests/Unit/Service/Attendance/AttendanceSummaryCalculatorTest.php (testCountsDaysPermissionAndLateness).
- **GIVEN** a pupil ill on two days (excused), absent without permission on one day, late twice by 10 and 5 minutes, and once left early
- **WHEN** the summary is counted
- **THEN** it shows 3 absent days, 2 with permission, 1 without, 2 times late and 15 minutes late

#### Scenario: A day with an excused and an unexcused lesson counts once, without permission
@e2e exclude Pure counting rule; covered by tests/Unit/Service/Attendance/AttendanceSummaryCalculatorTest.php (testADayWithMixedAbsencesCountsOnceWithoutPermission).
- **GIVEN** a secondary school pupil excused for the first lesson and absent without permission for the third lesson on the same day
- **WHEN** the summary is counted
- **THEN** the day adds 1 to `absentDays` and 1 to `absentUnauthorisedDays`

#### Scenario: July and August fall in different school years
@e2e exclude Pure counting rule; covered by tests/Unit/Service/Attendance/AttendanceSummaryCalculatorTest.php (testTheSchoolYearTurnsOnTheFirstOfAugust).
- **GIVEN** an absence on 10 July 2026 and one on 24 August 2026
- **WHEN** the summaries are counted
- **THEN** the first counts in 2025-2026 and the second in 2026-2027

### Requirement: The summary follows every attendance write
Every create, update and delete of an `AttendanceRecord` MUST lead to a recount of that learner's school year, deferred to a background job. The recount MUST count the whole school year from the records, never add to the stored numbers, and MUST save only when a number changed. An update that moves a record to another lesson MUST recount the school year of the old lesson too.

#### Scenario: A teacher's roll-call updates the summary
@e2e exclude Listener and deferred job with no screen of their own; covered by tests/Unit/Listener/AttendanceSummaryListenerTest.php (testACreatedRecordDefersARecount, testADeletedRecordDefersARecount) with the real OpenRegister event classes, by tests/Unit/BackgroundJob/AttendanceSummaryRecomputeJobTest.php, and checked live.
- **GIVEN** a pupil with no absences this school year
- **WHEN** the teacher marks the pupil absent without permission and the background job runs
- **THEN** the pupil's summary shows 1 absent day without permission

#### Scenario: Approving an absence report moves the day to with permission
@e2e exclude Covered by tests/Unit/Service/Attendance/AttendanceSummaryServiceTest.php (testARecountReplacesTheStoredNumbers).
- **GIVEN** a pupil's day counted without permission
- **WHEN** an absence report for that day is approved and the record becomes `absent-excused`
- **THEN** the summary counts the day with permission

### Requirement: Who reads an attendance summary
A member of `instructors` MUST read only the summaries whose `teacherIds` contains them; the server MUST stamp `teacherIds` with the teachers of the pupil's current groups on every recount. Members of `coordinators`, `administration-managers` and `compliance-officers` MUST read every summary. Nobody MUST create or update a summary through the API.

#### Scenario: A teacher reads the summaries of their own pupils
@e2e exclude Register rule; covered by tests/Unit/Settings/AttendanceSummaryRegisterTest.php (testReadRulesAndNoClientWrites).
- **GIVEN** the teacher of Groep 7
- **WHEN** they list attendance summaries
- **THEN** they see the summaries of Groep 7 pupils only

### Requirement: Existing records get their summaries
A repair step MUST count the summary of every learner with attendance records, with the same rules. A second run MUST save nothing.

#### Scenario: An upgraded school gets its summaries
@e2e exclude Repair step with no screen; covered by tests/Unit/Repair/BackfillAttendanceSummariesTest.php (testCountsEveryLearnerOnce).
- **GIVEN** a school with a year of attendance records and no summaries
- **WHEN** the app is upgraded
- **THEN** every learner with records has a summary for each school year they were marked in

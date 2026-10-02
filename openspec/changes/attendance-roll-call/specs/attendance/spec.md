## ADDED Requirements

### Requirement: A group teacher takes the day's register of their group in one screen
The roll-call page MUST list every pupil of one group for one day, each starting as present. A teacher MUST be able to mark a pupil late with a number of minutes (quick choices 5, 10 and 15, or any whole number from 1 to 600), absent with permission with a reason (`illness`, `appointment` or `other`), or absent without permission, with one tap or key per pupil. One save MUST write one `AttendanceRecord` per changed pupil, carrying `lateMinutes` for a late mark and `absenceReasonKind` for an absence, and MUST leave unchanged pupils alone. A late mark MUST name its minutes and an absence with permission MUST name its reason, or the save is refused. The page MUST work at phone width, with the keyboard alone, and with a screen reader.

#### Scenario: The teacher marks one pupil late and one absent without permission
@e2e exclude Covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testSaveWritesLateMinutesAndAnUnauthorisedAbsence) and checked live on the throwaway primary school as po-leerkracht-09 for Groep 7.
- **GIVEN** the teacher of Groep 7 opens today's register
- **WHEN** they mark Vera late by 10 minutes and Daan absent without permission, and save
- **THEN** Vera's record has status `late` and `lateMinutes` 10
- **AND** Daan's record has status `absent-unexcused`
- **AND** the other pupils are recorded present

#### Scenario: A late mark without minutes is refused
@e2e exclude Validation on the endpoint; covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testALateMarkNeedsItsMinutes).
- **GIVEN** a roll-call save with a late mark and no minutes
- **WHEN** it reaches the server
- **THEN** nothing is written and the answer names the pupil

### Requirement: Who may open which group's register
A member of `instructors` MUST open only the registers of groups whose `teacherIds` or `teacherAssignments` list them. Members of `coordinators`, `administration-managers` and `admin` MUST open the register of any group. Anyone else MUST be refused. The server MUST check this on every read and save, and MUST write the records itself.

#### Scenario: A teacher cannot open another group
@e2e exclude Access rule on the endpoint; covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testATeacherCannotOpenAnotherGroup).
- **GIVEN** the teacher of Groep 7
- **WHEN** they ask for the register of Groep 3
- **THEN** the answer is refused and nothing is shown

#### Scenario: A coordinator opens any group
@e2e exclude Access rule on the endpoint; covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testACoordinatorOpensAnyGroup) and checked live as po-ib-01.
- **GIVEN** a member of `coordinators`
- **WHEN** they open the roll-call
- **THEN** they can choose every group of the school

### Requirement: An approved absence report fills in the register
For a pupil without a saved mark that day, an `ExcuseRequest` in `approved` that covers the day MUST pre-fill "absent with permission", with `absenceReasonKind` taken from the report (`illness` stays `illness`, `medical-appointment` becomes `appointment`, anything else `other`) and the report's reason as the note. Saving it MUST link the record to the report through `excuseRequestId`. A report still in `submitted` MUST be shown next to the pupil and MUST NOT change the mark.

#### Scenario: A parent's approved sick report shows up
@e2e exclude Covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testAnApprovedReportPrefillsAnAuthorisedAbsence).
- **GIVEN** an approved report for Vera from Monday to Wednesday, reason "Griep", kind `illness`
- **WHEN** the teacher opens Tuesday's register
- **THEN** Vera is marked absent with permission, reason ill, note "Griep"

### Requirement: The register can be changed the same day
A group teacher MUST be able to save today's register as often as needed. An earlier day MUST be read-only for a group teacher once a teacher saved it, and MUST stay open to them while nobody saved it. Coordinators, administration-managers and admins MUST be able to save any day up to today. Nobody MUST save a day after today.

#### Scenario: Yesterday is read-only for the teacher
@e2e exclude Covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testAnEarlierSavedDayIsReadOnlyForTheTeacher).
- **GIVEN** the teacher saved yesterday's register
- **WHEN** they open it today
- **THEN** it is shown read-only, with a note to ask a coordinator

### Requirement: A day without a lesson gets one when the register is saved
When the group has no lesson that day, saving the register MUST create one `Session` for the group and day, titled with the group's name and the date, and timed like the group's most recent earlier lesson on the same weekday, or 08:30 to 14:30 when it has none. It MUST NOT create a second lesson when one exists.

#### Scenario: The first register of a new school year
@e2e exclude Covered by tests/Unit/Service/Attendance/RollCallServiceTest.php (testSavingADayWithoutALessonCreatesOne) and checked live.
- **GIVEN** Groep 7 has no lesson on 2 October 2026
- **WHEN** the teacher saves that day's register
- **THEN** a lesson "Groep 7, 2 October 2026" exists and the records point at it

### Requirement: The teacher reaches the register from the menu and the dashboard
The menu MUST offer "Today's register" to `instructors`, `coordinators` and `administration-managers`. On the teacher dashboard, "Sessions to mark" MUST list lessons that started today or earlier, newest first, and a click MUST open the roll-call for that lesson's group and day.

#### Scenario: The newest lesson comes first
@e2e exclude Covered by tests/unit-js/rollCall.test.mjs (sessionsToMarkFilter) and checked live on the teacher dashboard.
- **GIVEN** a group teacher with lessons on Monday and Tuesday
- **WHEN** they open the teacher dashboard on Tuesday
- **THEN** Tuesday's lesson is first in "Sessions to mark"
- **AND** a click opens Tuesday's roll-call for that group

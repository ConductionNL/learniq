# attendance Specification

## ADDED Requirements

### Requirement: A teacher opens a self check-in window for a lesson

A user in `instructors` or `compliance-officers` MUST be able to open a self check-in window for a session from the register screen, choosing `rotating-qr` for a lesson in a room or `link` for an online lesson. A window MUST close at its `closesAt`, which MUST NOT be later than the session's end, or earlier when the teacher closes it. A learner MUST NOT be able to read a `CheckInWindow` through any route.

#### Scenario: A teacher shows the check-in code on the board

- **GIVEN** a teacher on the register screen of "Wiskunde B, 4 havo" on Tuesday at 08:30
- **WHEN** the teacher chooses "Open self check-in" in the room mode
- **THEN** a full-screen QR code appears with the number of learners checked in so far
- **AND** the code changes every thirty seconds

### Requirement: The check-in code changes every thirty seconds in the room

In `rotating-qr` mode the code MUST be derived from the window id and the current thirty-second step and MUST NOT be stored; the server MUST accept the current and the previous step only. In `link` mode one code MUST hold for the whole window.

#### Scenario: An old photo of the code does not work

<!-- @e2e exclude Time-based code rule; covered by CheckInCodeServiceTest with a fixed clock. -->

- **GIVEN** a code taken from the board two minutes ago
- **WHEN** a learner of the group sends it
- **THEN** the check-in is refused with the reason that the code has expired

### Requirement: A learner checks in with the code

A signed-in learner who is in the `learnerIds` of the session's cohort MUST be able to check in with a valid code while the window is open. The server MUST write one `AttendanceRecord` with `status` `present`, or `late` when the check-in comes after the session start plus `lateAfterMinutes`, with `markedVia: self-check-in`. A caller outside the cohort, after the window closed, or with an invalid code MUST be refused with a plain reason and nothing written.

#### Scenario: A learner scans the code at the start of the lesson

- **GIVEN** learner m.yilmaz in the cohort of "Wiskunde B, 4 havo" and an open window
- **WHEN** m.yilmaz scans the QR code on the board and taps "Check in"
- **THEN** m.yilmaz sees that attendance is recorded
- **AND** the teacher's register shows m.yilmaz as present, labelled checked in

#### Scenario: A learner checks in after the grace period

<!-- @e2e exclude Status rule on the endpoint; covered by CheckInControllerTest::testLateAfterThreshold. -->

- **GIVEN** a session starting at 08:30 with `lateAfterMinutes: 5`
- **WHEN** a learner of the cohort checks in at 08:41
- **THEN** the record is written with status `late`

#### Scenario: A learner of another group is refused

<!-- @e2e exclude Access rule on the endpoint; covered by CheckInControllerTest::testRefusesCallerOutsideCohort. -->

- **GIVEN** a learner who is not in the session's cohort
- **WHEN** they post a valid code to `POST /api/check-in/{windowId}`
- **THEN** the answer says they are not in this lesson's group and no record is written

### Requirement: A self check-in never overwrites a mark

The check-in endpoint MUST NOT create a second record or change an existing `AttendanceRecord` for the same session and learner. The teacher MUST be able to change a self check-in record like any other; saving it MUST set `markedVia` to `teacher`.

#### Scenario: The teacher already marked a learner absent

- **GIVEN** the teacher marked learner t.devries `absent-excused` for the session
- **WHEN** t.devries checks in with a valid code
- **THEN** t.devries is told the attendance is already recorded
- **AND** the register still shows `absent-excused`

## ADDED Requirements

### Requirement: A planner books a room or lab for a session and an overfull booking is flagged

A planner MUST be able to book a `Room` of any kind (`classroom`, `lab`, `gym`, `auditorium`, `online` or `other`) for a `Session` by setting its `roomId`. The room's page MUST list the sessions booked in it. A booking whose cohort holds more learners than the room's `capacity` MUST be flagged as `room-capacity-exceeded`, without changing the booking.

#### Scenario: A planner books the chemistry lab

- **GIVEN** a room "Scheikundelokaal" of kind `lab` with capacity 24
- **WHEN** a planner sets it as the room of Tuesday's chemistry lesson for "4 havo"
- **THEN** the lesson carries that `roomId`
- **AND** the lab's page lists the lesson under booked sessions

#### Scenario: A lesson booked in a room that is too small is flagged

- **GIVEN** a room with capacity 24 and a cohort of 28 learners
- **WHEN** a planner books an ordinary lesson of that cohort in the room
- **THEN** a `TimetableConflict` of kind `room-capacity-exceeded` names the lesson and the room
- **AND** the lesson keeps its room, time and teacher

### Requirement: A coordinator acknowledges and resolves a timetable conflict by hand

The timetable conflict queue MUST list `open` and `acknowledged` conflicts and let a coordinator acknowledge or resolve each one. Acknowledging or resolving a conflict MUST change only the conflict's `lifecycle`. The sessions it names MUST stay as they are until a person changes them.

#### Scenario: A coordinator acknowledges a room double booking

- **GIVEN** an `open` conflict of kind `room-double-booking` naming two lessons in room 2.14
- **WHEN** a coordinator chooses "Acknowledge" on it in the queue
- **THEN** the conflict reads `acknowledged` and stays in the queue
- **AND** both lessons keep their room, time and teacher

#### Scenario: A coordinator resolves the conflict after moving a lesson

- **GIVEN** the coordinator moved one of the two lessons to another room on its own page
- **WHEN** they choose "Resolve" on the conflict
- **THEN** the conflict becomes `resolved` and leaves the queue

### Requirement: An import started from the timetable page reports its outcome and refreshes the conflicts

An administrator or coordinator who holds `exchange.request` MUST be able to choose a rostering system in the import dialog and start the import. Learniq MUST ask integriq to deliver that system's lessons to planninq, read the delivered lessons back and scan them for conflicts. The dialog MUST say the timetable was delivered and in which state, and the conflict queue MUST reload.

#### Scenario: A coordinator imports from Zermelo

- **GIVEN** planninq and integriq are installed and a coordinator holds `exchange.request`
- **WHEN** they choose "Import a timetable", pick Zermelo and choose "Import"
- **THEN** the dialog says the timetable was delivered to planninq, with the job's state
- **AND** the delivered lessons are scanned for conflicts and the conflict queue reloads

#### Scenario: Planninq is not installed

- **GIVEN** planninq is not installed
- **WHEN** a coordinator opens the timetable conflicts page
- **THEN** the import button is disabled with a message that planninq is needed

### Requirement: A lesson moved to another room or time notifies the affected learners and parents

When a lesson moves to another room or another time, learniq MUST notify every learner of the lesson's cohort and every parent listed on their profiles, with the lesson and its new room or time.

#### Scenario: A coordinator moves three weeks of a lesson to another room

- **GIVEN** "Wiskunde B, 4 havo" on three Tuesdays in room 2.14
- **WHEN** the coordinator changes the room to 1.08 for the three lessons in one batch
- **THEN** each learner of the group and each listed parent gets one message "Your timetable has changed" naming the three dates

#### Scenario: A single lesson moves to another room

- **GIVEN** next Tuesday's lesson of "Wiskunde B, 4 havo" in room 2.14
- **WHEN** a coordinator changes only that lesson's room to 1.08
- **THEN** each learner of the group and each listed parent gets a message naming the lesson and room 1.08

#### Scenario: A lesson moves to another time

- **GIVEN** next Tuesday's lesson at 10:15
- **WHEN** a coordinator moves it to 13:00 the same day
- **THEN** each learner of the group and each listed parent gets a message naming the new time

## MODIFIED Requirements

### Requirement: Conflict detection flags double-bookings and capacity overruns without resolving them

The system MUST detect, and MUST NOT automatically resolve, scheduling conflicts among `Session` objects
within an affected time window: a teacher (or, once substituted, the assigned `substituteTeacherId`)
double-booked across overlapping Sessions; the same `Room` double-booked; the same `Cohort` scheduled twice
at once; a learner (resolved via their cohorts' `learnerIds`) double-booked across Sessions in different
cohorts; a `Room`'s `capacity` exceeded by the learner count of any Session, an ordinary lesson as much as one
linked to an `Assessment` (`room-capacity-exceeded`); and any of the above overlap kinds where at least one Session is linked to an
`Assessment` (`exam-clash`). Detection MUST run as an OR-event-driven scan (on `Session` create/update, and
once in batch after a `timetable-import` `DataExchangeJob` reaches `succeeded`), scoped to the affected date
window — never a full-register scan. Each finding MUST be persisted as a `TimetableConflict` object; the
detector MUST NOT edit, cancel, or reassign any `Session` itself.

#### Scenario: Two Sessions imported for the same room at overlapping times are flagged, not auto-moved

<!-- @e2e exclude Cross-object conflict-scan logic is backend-only (TimetableConflictDetector), PHPUnit-verified; no DOM surface for the scan itself — the resulting queue view is covered by a separate scenario below. -->

- **GIVEN** two `Session` objects with the same `roomId` and overlapping `[startsAt, endsAt)` intervals
- **WHEN** the conflict detector scans the affected window
- **THEN** a `TimetableConflict` with `kind: room-double-booking` is created referencing both `sessionIds`
- **AND** neither `Session`'s `roomId`, `startsAt`, or `endsAt` is modified by the detector

#### Scenario: Re-scanning an unchanged window does not create duplicate conflicts

<!-- @e2e exclude Idempotent conflict-row matching is backend PHPUnit-verified logic; no DOM surface. -->

- **GIVEN** an `open` `TimetableConflict` already exists for a given `sessionIds` pair and `kind`
- **WHEN** the same window is re-scanned without the underlying Sessions changing
- **THEN** no second `TimetableConflict` row is created for that pair and kind

#### Scenario: An exam Session exceeding room capacity is flagged as room-capacity-exceeded

<!-- @e2e exclude Backend capacity comparison (Cohort.learnerIds length vs Room.capacity); PHPUnit-verified; no DOM surface for the comparison itself. -->

- **GIVEN** a `Session` linked to an `Assessment`, assigned a `Room` with `capacity: 30`, whose cohort has 34
  `learnerIds`
- **WHEN** the conflict detector scans the Session
- **THEN** a `TimetableConflict` with `kind: room-capacity-exceeded` is created referencing the Session and
  the Room

#### Scenario: An ordinary lesson exceeding room capacity is flagged as room-capacity-exceeded

- **GIVEN** a `Session` linked to no `Assessment`, assigned a `Room` with `capacity: 24`, whose cohort has 28
  `learnerIds`
- **WHEN** the conflict detector scans the Session
- **THEN** a `TimetableConflict` with `kind: room-capacity-exceeded` is created referencing the Session and
  the Room
- **AND** the Session keeps its `roomId`, `startsAt`, `endsAt` and teacher

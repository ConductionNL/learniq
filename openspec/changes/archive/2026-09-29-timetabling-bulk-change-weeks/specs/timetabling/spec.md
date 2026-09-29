# timetabling Specification

## ADDED Requirements

### Requirement: A coordinator applies one change to several weeks

From a lesson's change dialog, a user who may change that lesson MUST be able to list the lessons of the same weekly slot (same group, course, weekday and start time) up to a chosen date, tick the ones to change, and apply one change (cancel, substitute teacher or other room) with one reason to all of them. The dialog MUST then show per lesson whether it was changed or refused, with the reason.

#### Scenario: A coordinator cancels three weeks of a lesson

- **GIVEN** "Wiskunde B, 4 havo" every Tuesday at 10:15, and its teacher away for three weeks
- **WHEN** the coordinator opens next Tuesday's lesson, chooses "Cancel", then "Apply to more weeks", ticks the next three Tuesdays, gives the reason teacher absence and applies
- **THEN** the three lessons show as cancelled
- **AND** the dialog lists all three as changed

### Requirement: Every lesson in a batch passes the same checks

Each lesson in a batch MUST go through the same transition and guard as a single change, as the user who made the batch, and MUST be checked for conflicts as a single change is. A lesson the guard refuses MUST be recorded as refused with the guard's reason, and the other lessons MUST still be changed.

#### Scenario: A lesson that already took place is refused

<!-- @e2e exclude Guard outcome inside a batch; covered by SessionChangeBatchServiceTest::testRefusedLessonDoesNotStopTheBatch. -->

- **GIVEN** a batch with four lessons, one of them already completed
- **WHEN** the batch is applied
- **THEN** three lessons are cancelled and the completed one is recorded as refused with the guard's reason

### Requirement: Affected people get one message per batch

A lesson changed as part of a batch MUST NOT send its own change message. The batch MUST send one message to every learner and parent affected by any of its changed lessons, listing the dates.

#### Scenario: A parent gets one message for three weeks

<!-- @e2e exclude Notification delivery through the register dialect; covered by SessionChangeBatchRegisterTest and gate 18. -->

- **GIVEN** a batch that cancelled three Tuesday lessons of a learner's group
- **WHEN** the batch is saved
- **THEN** the learner's parent gets one message "Your timetable has changed" listing the three dates

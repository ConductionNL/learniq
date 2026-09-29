# Design: schedule exams and test weeks with rooms and invigilators

## Context

At development `acdf1dd5`:

- `lib/Settings/learniq_register.json`: `Assessment.sessionId` (`:8337`), `Room.capacity` (`:5938`), `TimetableConflict.kind` includes `exam-clash` (`:6324`), `ExamAccommodation` and its approval guard.
- `lib/Timetabling/TimetableConflictDetector.php:222-227` flags any overlap as an exam-clash when either Session has a linked Assessment.
- `lib/Service/TimetableProjector.php` and `TimetableController` show sessions; sittings can be projected as sessions with an assessment link so learners see them in their timetable.
- Learniq's timetabling design lists generation as a permanent non-goal; this change places by hand with checks.

## Goals / Non-Goals

**Goals**
- A test week can be planned by a person with room, clash, accommodation and invigilator checks.

**Non-Goals**
- Automatic generation of an exam schedule.
- Fair distribution of invigilation duty (`tt-invigilation-fair-share`, decided no).
- Oral exams (`tt-oral-exams`, decided no).

## Decisions

### D1: Sittings are projected as sessions

Projecting each sitting as a `Session` with an assessment link lets learners and the calendar feed show exams with no second timetable path, and reuses the conflict detector.

### D2: Accommodations are read, not copied

The sitting resolves entitlements at read time from approved `ExamAccommodation` objects, so a revoked accommodation stops applying at once.

### D3: Invigilators confirm

A pending state stops a planner assuming someone will be there; a decline reopens the slot instead of failing silently.

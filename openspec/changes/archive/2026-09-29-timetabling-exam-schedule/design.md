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

### D1: Sittings are checked at write time, not projected as sessions (changed while building, 2026-09-29)

The first version of this design projected each sitting as a `Session` and reused `TimetableConflictDetector`. At development `21c17a01` that path cannot work: the detector runs from `SessionConflictListener`, which is behind the `listener_slug_contract` switch (off by default), and its window query filters on `sessionDayBucket`, a property the Session schema does not declare, so OpenRegister matches nothing. Instead `ExamSittingPlacementCheck` runs on every ExamSitting create and update (a pre-write listener, resolved through `ListenerSchemaResolver::guardSchemaSlug`, which is not behind the switch): it refuses rooms whose capacities add up to less than the headcount, and it records lessons (per room and per class) and other sittings in the same test week that overlap, in `clashWarnings`. A clash is a warning, not a refusal: a class often sits an exam instead of its lesson. Showing sittings in a learner's timetable is left for a follow-up.

### D2: Accommodations are read, not copied

The sitting resolves entitlements at read time from approved `ExamAccommodation` objects, so a revoked accommodation stops applying at once.

### D3: Invigilators confirm

A fourth schema, `InvigilatorAssignment` (sitting, invigilator, `pending` / `confirmed` / `declined`), carries the request. `InvigilatorResponseGuard` lets only the invigilator named on it confirm or decline; `InvigilatorAssignmentCheck` refuses a request for someone whose `InvigilatorAvailability` does not cover the whole sitting, or who already holds an open request for it, and starts every request pending. `ExamSittingOverview` (served at `GET /api/exam-sittings/{id}/overview` and `/available-invigilators`, planner groups only) counts confirmed, pending and open places and reads approved accommodations at request time.


A pending state stops a planner assuming someone will be there; a decline reopens the slot instead of failing silently.

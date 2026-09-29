# Design: place a student's elective choices in their timetable

## Context

At development `acdf1dd5`:

- `lib/Controller/TimetableController.php:121-160` resolves `cohortIds` and calls `$source->sessionsForCohorts` and `sessionsForTeacher`, merged by id.
- `lib/Listener/SubjectChoiceEnrolmentBridge.php` creates `Enrolment` rows with `source: subject-choice` on `approved -> locked`.
- `src/manifest.d/learning.json:240-257` declares `SubjectChoices` and `SubjectChoicePicker`.
- `Session` has `courseId` and `cohortId`; enrolments have `courseId` and `learnerId`.

## Goals / Non-Goals

**Goals**
- A learner's timetable shows the lessons they actually attend.

**Non-Goals**
- Group swaps (`tt-group-swap`, decided no).
- Capacity or waitlist changes.

## Decisions

### D1: Enrolment based, not choice based

Any active enrolment puts its course sessions in the timetable, so electives from any source (choice, catalogue sign-up, manager) behave the same.

### D2: Warn, do not block

Overlaps are shown as a warning; the school decides whether an overlap is allowed.

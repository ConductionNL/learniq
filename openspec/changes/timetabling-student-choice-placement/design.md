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

### D3: Only course-only enrolments add course lessons (built 1 Oct)

An enrolment with a cohort already brings that cohort's lessons. Asking for its course as well would bring every other group's lessons of the same course (a core course taught to five groups). So only a live enrolment with no `cohortId`, which is what an approved subject choice creates (`SubjectChoiceEnrolmentBridge`), adds its course's lessons through the new `TimetableSource::sessionsForCourses()`. An enrolment whose lifecycle is `withdrawn`, `completed` or `failed` adds nothing, by course or by cohort. `pending` and `active` count: a locked choice creates a pending enrolment, and the learner already attends.

Planninq's query takes no course and its lessons carry no learniq course, so the planninq source answers `sessionsForCourses()` with nothing and sends no query. With planninq, an elective reaches the learner through the elective group's cohort.

### D4: Slots come from a small route, read with the caller's rights

The picker reads OpenRegister directly; it has no data route to extend. `GET /api/timetable/course-slots?courseIds=&withCore=` (`ElectiveSlotsController`, `ElectiveSlotService`) reads each course with the caller's OpenRegister rights first and leaves out any it cannot read, then reduces that course's lessons over the next four weeks to distinct weekly slots in the reader's time zone. `withCore=1` adds the caller's own other lessons from `PersonalTimetableService`, so the clash check uses the same lessons as My timetable. The picker asks for core lessons only when the learner chooses for themselves: a guardian choosing for a child gets the electives compared with each other only. The overlap logic is `src/utils/electiveSlots.js`.

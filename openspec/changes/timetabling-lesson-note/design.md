# Design: timetabling-lesson-note

## Context

The personal timetable is a projection: `TimetableController::mine()` (`lib/Controller/TimetableController.php:111-160`) resolves the caller's cohorts, loads their sessions and passes them through `TimetableProjector` (`lib/Service/TimetableProjector.php:127`, :201). `MyTimetable.vue` renders the result. A note belongs next to a lesson without being part of the timetable data, so it is its own learniq object.

## Sessions after D10

A `LessonNote` names its lesson by `sessionId` (learniq `Session` uuid) today, or by `timetableSessionRef` (`{sourceSystem, externalRef}` of a planninq `timetableSession`, contract version 1 of planninq's school-timetable-target) after learniq's follow-up `sessions-from-planninq`. One of the two is required. The projector matches notes to lessons on whichever key the lesson has.

## Data model

### `LessonNote` (new, slug `lesson-note`, 0.1.0)

| property | type | notes |
|---|---|---|
| `sessionId` | uuid, `$ref: Session`, nullable | |
| `timetableSessionRef` | object `{sourceSystem, externalRef}`, nullable | |
| `cohortId` | uuid, `$ref: Cohort`, required | copied from the lesson at create, for the read rule and filtering |
| `topic` | string, max 120, nullable | "Hoofdstuk 4: kansrekening" |
| `text` | string, max 2000, required | |
| `audience` | `learners`, `cover` | `cover` is for the covering teacher and staff only |
| `authorId` | string, required | |
| `tenant_id` | string, required | |

Authorization: read `instructors`, `team-leads`, `compliance-officers`; create and update `instructors`, `team-leads`, `compliance-officers`. Learners read notes only through the timetable endpoint, which returns `audience: learners` notes of lessons that are in the learner's own timetable. A `LessonNoteAuthorGuard` on create and update refuses an instructor who is neither in the lesson cohort's `teacherIds` nor its substitute, so one teacher cannot write on another's lessons (the check `SessionChangeGuard` makes for changes, `lib/Lifecycle/SessionChangeGuard.php:139`). It runs as an `ObjectCreatingEvent` and `ObjectUpdatingEvent` listener, because OpenRegister runs no lifecycle guard on create.

## Timetable endpoint

- `TimetableController::mine()` loads, for the lessons in the window, the notes keyed on those lessons in one read, and passes them to the projector.
- `TimetableProjector::projectSession()` adds `notes: [{topic, text, audience}]`, keeping only `learners` notes when the caller is a learner of the cohort, and all notes when the caller teaches, covers or is staff.
- `resolveCallerCohortIds()` is joined by a second lookup: sessions where `substituteTeacherId` equals the caller in the window. They are added with a flag `cover: true`.

## Screens

- `MyTimetable.vue`: a small note icon on a lesson with notes; the lesson detail (the existing click-through) shows the topic and text. Covered lessons show "Cover".
- `SessionDetail` (`src/manifest.d/learning.json:2563`): a "Notes" widget listing the lesson's notes, with "Add note" for its teachers and coordinators, and a series option that creates the same note on every lesson of the same cohort and course in the chosen weeks.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| schema and read rule for staff | declarative | |
| only a lesson's own teachers write notes | imperative, creating and updating listener | a rule across rows (cohort teachers, substitute), ADR-031 guard exception |
| notes and cover lessons in the timetable | existing controller and projector | the timetable endpoint is already the learner's only read path to lessons |

## Seed data

VO example set: three `LessonNote` rows on "Wiskunde B, 4 havo": a topic note for learners ("Hoofdstuk 4: kansrekening, neem je rekenmachine mee"), a series note for the next three weeks, and one `cover` note ("Laat ze opgave 12 tot 18 maken; Sanne mag eerder weg voor de tandarts").

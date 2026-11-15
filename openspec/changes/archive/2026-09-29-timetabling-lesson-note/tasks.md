# Tasks: timetabling-lesson-note

## Implementation tasks

### Task 1: Register: LessonNote
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson`
- **files**: `lib/Settings/learniq_register.json` (new schema with authorization; `info.version` bump)
- [x] Implement (LessonNote 0.1.0, info.version 0.32.0; `authorId` is stamped by the server, so it is not in `required`)
- [x] Test: `tests/Unit/Settings/LessonNoteRegisterTest.php`; the register ratchet tests stay at their current set (`tests/Unit/Register` green)

### Task 2: Author rule
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson`
- **files**: `lib/Listener/LessonNoteAuthorGuard.php`, its registrar
- [x] Implement (`lib/Listener/LessonNoteAuthorGuard.php`, wired in `IntegrityListenerRegistrar` on create and update)
- [x] Test: `tests/Unit/Listener/LessonNoteAuthorGuardTest.php` with the real creating event (own lesson allowed, another cohort refused, substitute allowed)

### Task 3: Notes and cover lessons in the timetable endpoint
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable`, `#requirement-a-substitute-teacher-sees-the-lessons-they-cover`
- **files**: `lib/Controller/TimetableController.php`, `lib/Service/TimetableProjector.php`
- **acceptance_criteria**:
  - GIVEN a cover note WHEN a learner loads the timetable THEN the note is not in the response
- [x] Implement (cover lessons already landed in learniq#1185, `TimetableController::mine()` reads `sessionsForTeacher()` and marks `cover: true`; notes through `lib/Service/LessonNoteReader.php` and `TimetableProjector::personalSessions()`)
- [x] Test: `tests/Unit/Controller/TimetableControllerTest.php` (learner, teacher, substitute), `tests/Unit/Service/LessonNoteReaderTest.php` (planninq reference, audience)

### Task 4: Timetable and lesson page
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable`
- **files**: `src/views/MyTimetable.vue`, `src/manifest.d/learning.json` (SessionDetail notes widget), a note modal under `src/modals/`
- [x] Implement (`src/dialogs/LessonNoteDialog.vue`, an NcDialog so it lives under `src/dialogs/`, with the series option; `src/utils/lessonNotes.js`; SessionDetail gets a declarative "Notes" object-list widget that adds a single note, because a detail page cannot host a custom component, so the series option lives in the timetable's "Add note")
- [x] Test: Playwright `tests/e2e/lesson-note.spec.ts` (teacher adds a series note; written, not run in this lane: the shared instance is off limits); learner and substitute sides in `TimetableControllerTest`; series rule in `tests/unit-js/lessonNotes.test.mjs`

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement (`scripts/example-sets/vo.py`: five notes on 4H1 Wiskunde B lessons, one topic, three series, one cover; mock register objects for gate 101)
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-lesson-note --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

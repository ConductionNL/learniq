# Tasks: timetabling-lesson-note

## Implementation tasks

### Task 1: Register: LessonNote
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson`
- **files**: `lib/Settings/learniq_register.json` (new schema with authorization; `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/LessonNoteRegisterTest.php`; the register ratchet tests stay at their current set

### Task 2: Author rule
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson`
- **files**: `lib/Listener/LessonNoteAuthorGuard.php`, its registrar
- [ ] Implement
- [ ] Test: `tests/Unit/Listener/LessonNoteAuthorGuardTest.php` with the real creating event (own lesson allowed, another cohort refused, substitute allowed)

### Task 3: Notes and cover lessons in the timetable endpoint
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable`, `#requirement-a-substitute-teacher-sees-the-lessons-they-cover`
- **files**: `lib/Controller/TimetableController.php`, `lib/Service/TimetableProjector.php`
- **acceptance_criteria**:
  - GIVEN a cover note WHEN a learner loads the timetable THEN the note is not in the response
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/TimetableControllerTest.php`, `tests/Unit/Service/TimetableProjectorTest.php`

### Task 4: Timetable and lesson page
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable`
- **files**: `src/views/MyTimetable.vue`, `src/manifest.d/learning.json` (SessionDetail notes widget), a note modal under `src/modals/`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/lesson-note.spec.ts` (teacher adds a series note, learner sees it, substitute sees the cover note)

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-lesson-note --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

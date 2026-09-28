# Tasks: teacher-notes-protection

Feature tier: must (privacy of pupil-related staff notes).

## Implementation Tasks

### Task 1: Register: LessonTeacherNote schema, Lesson enum, versions, seed and mock rows, catalogue
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-teacher-notes-live-in-a-store-only-staff-can-read`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/nl.json`, `tests/Unit/Register/TeacherNoteLearnerAccessTest.php`, `tests/Unit/Settings/LessonOnboardingRegisterTest.php`, `tests/unit-js/lessonOnboarding.test.mjs`
- **acceptance_criteria**:
  - GIVEN the register WHEN a learner, a guardian and a teacher are evaluated THEN only the teacher matches (TC-1)
  - GIVEN a lesson body with a teacherNote block WHEN validated THEN it fails (TC-2)
  - GIVEN the new strings WHEN check:schema-l10n runs THEN it passes
- [x] Implement
- [x] Test

### Task 2: One splitter for server-side note moves
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store`
- **files**: `lib/Service/LessonOnboarding/TeacherNoteSplitter.php`, `tests/Unit/Service/LessonOnboarding/TeacherNoteSplitterTest.php`
- **acceptance_criteria**:
  - GIVEN [A, N, B, N2, N3] WHEN split THEN blocks [A, B] and notes N after A (0), N2 after B (0), N3 after B (1)
  - GIVEN a note first WHEN split THEN its afterBlockId is empty
- [x] Implement
- [x] Test

### Task 3: The onboarding importer writes notes to the staff store
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store`
- **files**: `lib/Service/LessonOnboarding/LessonOnboardingImporter.php`, `tests/Unit/Service/LessonOnboarding/LessonOnboardingImporterTest.php`
- **acceptance_criteria**:
  - GIVEN a presentation with a slide note WHEN imported THEN the lesson has no teacherNote and one lesson-teacher-note is created after the final blocks (TC-4)
- [x] Implement
- [x] Test

### Task 4: Upgrade step moves existing notes
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold`
- **files**: `lib/Repair/MoveTeacherNotesOutOfLessons.php`, `appinfo/info.xml`, `tests/Unit/Repair/MoveTeacherNotesOutOfLessonsTest.php`
- **acceptance_criteria**:
  - GIVEN a lesson with a note WHEN run THEN note created, lesson saved without it (TC-5)
  - GIVEN the note already exists WHEN run THEN nothing created, lesson stripped
  - GIVEN a failing create WHEN run THEN the lesson is not saved
- [x] Implement
- [x] Test

### Task 5: The composer loads and saves notes separately
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store`
- **files**: `src/utils/lessonBlocks.js`, `src/views/LessonComposer.vue`, `tests/unit-js/teacherNotes.test.mjs`
- **acceptance_criteria**:
  - GIVEN blocks and notes WHEN merged, split and diffed THEN the TC-3 results
  - GIVEN a save WHEN it runs THEN the lesson PATCH carries no teacherNote and notes are created, updated and deleted by blockId
- [x] Implement
- [x] Test

### Task 6: Docs and copy
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-teacher-notes-live-in-a-store-only-staff-can-read`
- **files**: `docs/user-guide/user/02-create-course.md` (or the lesson composer doc), `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the teacher guide WHEN read THEN it says learners never see teacher notes, in the player or elsewhere
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for every new or changed class; the full suite once in `check:strict`, compared by failure set with development.
- `node --test tests/unit-js/*.test.mjs`, compared by failure set with development.
- Register tests under `tests/Unit/Register/` run and pass for the new schema.
- `npm run check:schema-l10n`, `npm run check:specs`, gate 101 (demo data) and gate 28 (property titles) pass.
- `openspec validate teacher-notes-protection` passes.

# Test Plan: teacher-notes-protection

## Test Cases

### TC-1: A learner and a guardian get no read on teacher notes
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-teacher-notes-live-in-a-store-only-staff-can-read`
- **type**: security
- **persona**: a learner (`learners`), a guardian (`guardians`), a teacher (`instructors`)
- **preconditions**: the shipped register
- **steps**: evaluate `LessonTeacherNote.authorization` the way OpenRegister does (group membership, `authenticated` for every signed-in user, match rules) for each principal and each action
- **expected result**: learner and guardian match no rule for read, create, update or delete; the teacher matches read and write; no rule names `authenticated`, `learners` or `guardians`; not searchable
- **test command**: `vendor/bin/phpunit --filter TeacherNoteLearnerAccessTest`

### TC-2: A lesson cannot hold a note
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-a-lesson-a-learner-can-read-cannot-hold-a-teacher-note`
- **type**: security
- **steps**: read `Lesson.blocks.items.properties.type.enum`; validate a lesson body with a `teacherNote` block against the Lesson schema
- **expected result**: the enum has no `teacherNote`; the body fails validation on `blocks[1].type`
- **test command**: `vendor/bin/phpunit --filter TeacherNoteLearnerAccessTest`

### TC-3: Split and merge in the composer
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store`
- **type**: functional
- **steps**: `splitTeacherNotes`, `mergeTeacherNotes`, `diffTeacherNotes` on sample blocks and notes
- **expected result**: notes leave the lesson blocks with the right anchor and position; merge restores the order, puts an orphan note first; diff names create, update and delete by `blockId`
- **test command**: `node --test tests/unit-js/teacherNotes.test.mjs`

### TC-4: The importer writes slide notes to the staff store
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store`
- **type**: functional
- **steps**: import a presentation with a note on slide 3 (object writer and extractor doubles)
- **expected result**: the lesson payload has no `teacherNote`; one `lesson-teacher-note` create with the note text, anchored to slide 3's block
- **test command**: `vendor/bin/phpunit --filter 'TeacherNoteSplitterTest|LessonOnboardingImporterTest'`

### TC-5: The upgrade moves notes once
- **spec_ref**: `openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold`
- **type**: regression
- **steps**: run the repair step over lessons with and without notes; rerun with the note already created; make one create fail
- **expected result**: note created then lesson saved without it; rerun creates nothing and strips the lesson; failed create keeps the note block and saves nothing
- **test command**: `vendor/bin/phpunit --filter MoveTeacherNotesOutOfLessonsTest`

## Coverage Summary
- Teacher notes live in a store only staff can read: TC-1.
- A lesson a learner can read cannot hold a teacher note: TC-2.
- The composer shows notes inline and saves them to the staff store: TC-3.
- Imported slide notes land in the staff store: TC-4.
- An upgrade moves the notes lessons already hold: TC-5.

## Out of Scope
A live request as a learner against a running Nextcloud: the lane keeps off the shared
instance. TC-1 evaluates the authorization block with OpenRegister's rule semantics instead,
and TC-2 validates with the schema OpenRegister itself validates against.

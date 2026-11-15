# Course management

## ADDED Requirements

### Requirement: Teacher notes live in a store only staff can read
Learniq MUST keep every teacher note as a `LessonTeacherNote` object (`schema:Comment`), never inside a `Lesson`. Its `authorization` MUST grant read, create, update and delete only to staff groups (`instructors`, `team-leads`, `coordinators`, `hr`, `compliance-officers`, `administration-managers` for read; the groups that may write lessons for create, update and delete) and MUST NOT contain `authenticated`, `learners` or `guardians`, with or without a match. The schema MUST NOT be searchable. FEATURES tier: must (privacy of pupil-related staff notes).

#### Scenario: A learner asks for the notes of a lesson
- **GIVEN** a learner in the `learners` group, and a lesson with the note "Sem heeft hier extra uitleg nodig"
- **WHEN** the learner lists `lesson-teacher-note` objects filtered on that lesson
- **THEN** OpenRegister's authorization gives the learner no read rule to match, and nothing is returned

#### Scenario: A guardian asks for a note by id
- **GIVEN** a guardian in the `guardians` group who knows a note's id
- **WHEN** the guardian requests it
- **THEN** no read rule admits the guardian

#### Scenario: A teacher reads the notes of a lesson
- **GIVEN** a teacher in `instructors`
- **WHEN** the composer lists the lesson's notes
- **THEN** the notes are returned

### Requirement: A lesson a learner can read cannot hold a teacher note
`Lesson.blocks[].type` MUST NOT accept `teacherNote`, so OpenRegister refuses any lesson write that carries a note, whoever sends it. This replaces the office-file-lesson-onboarding requirement that `Lesson.blocks[].type` accepts `teacherNote`; the composer label and the player filter stay.

#### Scenario: A stale client saves a note inside a lesson
- **GIVEN** a lesson body with a `teacherNote` block
- **WHEN** it is saved to the objects API
- **THEN** schema validation refuses it and the stored lesson is unchanged

### Requirement: The composer shows notes inline and saves them to the staff store
`LessonComposer` MUST load the lesson's `LessonTeacherNote` objects and show each at its place among the blocks: after the block named by `afterBlockId`, or first when that is empty or no longer exists, ordered by `position`. On save it MUST write the lesson's blocks without any note, then create each new note, update each changed note, and delete each note the teacher removed, matched by `blockId`. A failed note write MUST report that the lesson was saved but a note was not.

#### Scenario: A teacher adds a note after the second block
- **GIVEN** a lesson with blocks A and B
- **WHEN** the teacher adds a note after B and saves
- **THEN** the lesson's `blocks` are A and B only, and one `LessonTeacherNote` exists with `afterBlockId` B

#### Scenario: A teacher deletes a note
- **GIVEN** a lesson with one stored note
- **WHEN** the teacher removes it in the composer and saves
- **THEN** that `LessonTeacherNote` is deleted

### Requirement: Imported slide notes land in the staff store
When the onboarding importer turns a presentation into a lesson draft, the lesson MUST receive only learner-facing blocks, and every slide's speaker note MUST be written as a `LessonTeacherNote` on that lesson, following the block of its slide.

#### Scenario: A presentation with speaker notes
- **GIVEN** a confirmed presentation whose slide 3 has the note "Vraag naar de rol van licht"
- **WHEN** it is imported
- **THEN** the lesson's blocks carry no note, and one `LessonTeacherNote` with that text follows slide 3's block

### Requirement: An upgrade moves the notes lessons already hold
An upgrade MUST move every `teacherNote` block of every lesson into a `LessonTeacherNote` with the block's `blockId`, `text`, the preceding block as `afterBlockId` and its order among the notes as `position`, and MUST then save the lesson without it. A note whose `lessonId` and `blockId` already exist MUST NOT be created again. A lesson whose note could not be created MUST keep its note block. The app `<version>` MUST move so `occ upgrade` runs the step.

#### Scenario: An imported lesson from before this change
- **GIVEN** a lesson with blocks A, a note N, and B
- **WHEN** the upgrade runs
- **THEN** the lesson's blocks are A and B, and a `LessonTeacherNote` with `blockId` N and `afterBlockId` A exists

#### Scenario: The upgrade runs again after a partial failure
- **GIVEN** the note for N was created but the lesson save failed
- **WHEN** the upgrade runs again
- **THEN** no second note is created and the lesson is saved without N

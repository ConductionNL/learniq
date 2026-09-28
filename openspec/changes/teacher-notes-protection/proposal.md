---
kind: code
---

# Proposal: teacher-notes-protection

## Summary
Teacher notes move out of the lesson and into a staff-only `LessonTeacherNote` schema that
learners and guardians cannot read. Today a note is a `teacherNote` block inside
`Lesson.blocks`: the lesson player hides it, but `Lesson` is readable by every signed-in
user, so a learner who calls the objects API gets every note in full. After this change a
lesson cannot hold a note at all (`teacherNote` leaves the block type list), the composer
still shows notes inline and saves them to the new schema, the onboarding importer writes
slide notes there, and an upgrade step moves the notes existing lessons already carry.

## Motivation
Round 2 lesson lane follow-up (learniq #1080, TRACKER-R2): "teacher notes are hidden not
protected". Measured on development 0171a896:
- `Lesson.authorization.read` is `["authenticated"]`, so any learner reads a lesson,
  including `blocks`.
- `playerVisibleBlocks()` filters `teacherNote` in the browser only.
- `LessonDraftBuilder` writes speaker notes of imported slides as `teacherNote` blocks, so
  every imported presentation publishes its speaker notes to the learners of the course.
- A course shared through the store exports its lessons, blocks included, so notes leave the
  school as well.

A teacher's note is written for colleagues ("ask about the role of light", "Sem struggles
here"). It can name pupils. Hiding it in one screen while serving it on the API is the
"guard with a full test suite and no call site" shape: the player test passes and the data
leaks.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (new `LessonTeacherNote`
  schema, `Lesson.blocks` type enum, versions), `lib/Settings/learniq_mock_register.json`,
  `lib/Service/LessonOnboarding/TeacherNoteSplitter.php` (new),
  `lib/Service/LessonOnboarding/LessonOnboardingImporter.php`,
  `lib/Repair/MoveTeacherNotesOutOfLessons.php` (new), `appinfo/info.xml`,
  `src/utils/lessonBlocks.js`, `src/views/LessonComposer.vue`, `l10n/`, docs, tests.

## Scope

### In Scope
- `LessonTeacherNote` (slug `lesson-teacher-note`): `lessonId`, `blockId` (stable id
  within the lesson), `afterBlockId` (the lesson block the note follows, empty for the
  start), `position`, `text`, `tenant_id`. Read, create, update and delete by staff only;
  no `authenticated`, `learners` or `guardians` entry. Not searchable. Seed row and mock
  rows.
- `Lesson.blocks.items.properties.type.enum` drops `teacherNote`, so OpenRegister refuses a
  lesson save that still carries a note.
- Composer: loads the lesson's notes, shows them inline where they were, saves lesson blocks
  and notes separately (create, update, delete of notes by `blockId`).
- Onboarding importer: slide notes become `LessonTeacherNote` objects after the lesson is
  created; the lesson keeps only learner-facing blocks.
- Upgrade: a repair step moves every `teacherNote` block of every lesson into the new schema,
  skipping a note whose `lessonId` and `blockId` already exist, then saves the lesson without
  it. Idempotent; bumps the app `<version>` so `occ upgrade` runs it.
- Tests from the learner side: a learner's groups get no read on the note schema, and a lesson
  body a learner can read cannot contain a note.

### Out of Scope
- Sharing teacher notes with another school through the store. Notes stay home; a copy
  installed elsewhere starts without them.
- Notes on other objects (courses, assessments).
- A notes history view; OpenRegister's audit trail keeps versions as for any object.

## Approach
A separate schema with its own authorization block, rather than a property-level rule on
`Lesson.blocks`: OpenRegister's property RBAC works per property, and `blocks` is one array
that mixes learner content and notes. A separate schema is enforced on every read path
OpenRegister has (objects API, search, exports, MCP) with the same machinery every other
staff-only schema uses.

## New Dependencies
None.

## Impact
- New schema `lesson-teacher-note`; `Lesson` 0.4.2 to 0.5.0 (enum narrowed); register
  `info.version` bumped.
- `LessonComposer` makes one extra list request on load and up to one request per changed
  note on save.
- `LessonOnboardingImporter` writes notes as separate objects.
- Upgrade step touches lessons that hold a `teacherNote` block, and only those.

## Cross-Project Dependencies
None. Uses OpenRegister's existing schema authorization.

## Risks

### Risk 1: A note is lost during the upgrade move
**Severity:** High. **Mitigation:** the step creates the note first and strips the lesson
only after the create succeeded; a rerun skips notes that already exist by
`lessonId` + `blockId`, so a failure between the two steps duplicates nothing and loses
nothing. Lessons it cannot rewrite are logged by id and left as they were.

### Risk 2: An older composer tab saves a note block after the upgrade
**Severity:** Low. **Mitigation:** OpenRegister refuses the save (the enum no longer has
`teacherNote`); the composer reports the save failed and a reload brings the new composer.

### Risk 3: Other open PRs change the register
**Severity:** Low. **Mitigation:** named in the PR body (#1124, #1126, #1139) so the landing
orders them and re-bumps `info.version`.

## Rollback Strategy
Revert the PR. Notes stay in the `lesson-teacher-note` schema (not shown by the old
composer); lessons carry no notes. A manual move back is not needed for learner safety.

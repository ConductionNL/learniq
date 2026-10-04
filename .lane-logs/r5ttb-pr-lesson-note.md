## Scope

A teacher adds a note to a lesson, or to the same lesson in the next weeks: a topic, the text, and who reads it. Learners see notes for learners in "My timetable"; a note for the covering teacher reaches only the lesson's teachers, its substitute and staff.

Builds `openspec/changes/timetabling-lesson-note` (planninq matrix row `tt-lesson-note`, planninq#665; Zermelo, Untis, Xedule and TimeEdit all rate yes, evidence in the proposal).

## What changed

- Register: new `LessonNote` schema (0.1.0), `info.version` 0.31.5 to 0.32.0. Read and write for `instructors`, `team-leads`, `compliance-officers`; learners have no direct read.
- `LessonNoteAuthorGuard` on create and update: only the cohort's teachers, the lesson's substitute, team leads and compliance officers write a note. The note must name the lesson's own cohort. The server stamps `authorId`, so it left `required`.
- `LessonNoteReader` reads the notes of the lessons in the window without the caller's RBAC and keeps what the caller may read. `TimetableProjector::personalSessions()` puts `notes` and `canAddNote` on every lesson of `GET /api/timetable/mine`.
- D10: a note names a learniq Session by `sessionId`, or a planninq lesson by `timetableSessionRef` (`sourceSystem`, `externalRef`). `PlanninqTimetableSource` now carries `sourceSystem` on its rows. Nothing is written into planninq.
- Cover lessons in the timetable were already built in learniq#1185; this PR shows them as "Cover" and gives the substitute the cover notes.
- `MyTimetable.vue`: topic on the lesson, notes in a collapsible list, "Cover" badge, "Add note" where the caller may add one. `LessonNoteDialog.vue` (NcDialog, so under `src/dialogs/`) has the series option.
- SessionDetail gets a declarative "Notes" list that adds a single note. A detail page cannot host a custom component, so the series option lives in the timetable's "Add note".
- VO example set: five notes on 4H1 Wiskunde B lessons (topic, three weeks of homework, one cover note). Mock register objects for gate 101. 37 new catalogue keys with Dutch values (marked AI-translated).

## Verified

- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: exit 0 (lint, phpcs, phpmd, psalm, phpstan clean; PHPUnit 2223 tests, 0 failures)
- `npm run lint`: exit 0; `npm run format`: exit 0; `npm run check:specs`: pass; `npm run check:schema-l10n`: exit 0 (2235 at baseline); `npm run check:l10n-js`: up to date
- `node --test tests/unit-js/lessonNotes.test.mjs`: 5 of 5 pass
- `npx openspec validate timetabling-lesson-note --strict`: valid
- `python3 scripts/example-sets/vo.py --check`: up to date after regenerating
- hydra gates `run-hydra-gates.sh --base origin/development`: exit 8; gate 5, 7, 16, 51 and 101 pass. The failing gates are all inherited, see below.
- Playwright `tests/e2e/lesson-note.spec.ts` is written and not run: the shared instance on :8080 is off limits to build lanes.

## Inherited

Gate 3 (six `check()` methods in Wallet, LearningRecord and ReportCardPdf services), 25 and 49 (`ComplianceRollupController`), 53 (the gate's own script fails under the workspace `"type": "module"`), 55 (CohortDetail layout overlap), 60 (`FileAccountOutline` not in `src/icons.js`), 112 and 113 fail on development too and touch no file of this PR. `npm run test:js-unit` has one inherited failure (`openregisterSchemaRefs`: `LessonComposer.vue` and `LessonPlayer.vue` name the schema `Assessment`).

No stacked base. Another lane touching `lib/Settings/learniq_register.json` or `TimetableController` in this round: the other timetabling changes of lane r5-timetabling-b (standby slots, visibility rules) touch the same files and are cut from development separately.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

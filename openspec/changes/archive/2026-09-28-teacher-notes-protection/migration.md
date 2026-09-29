# Migration: teacher-notes-protection

## Current State
`Lesson` objects may carry `teacherNote` blocks in `blocks`, each `{blockId, type: "teacherNote", order, text}`.
Every signed-in user can read them through the objects API.

## Target State
No lesson carries a `teacherNote` block. Each former note block is a `lesson-teacher-note`
object `{lessonId, blockId, afterBlockId, position, text, tenant_id}` readable by staff only.

## Migration Class
No database migration; a repair step, because the data lives in OpenRegister objects:
```
File: lib/Repair/MoveTeacherNotesOutOfLessons.php
Registered: appinfo/info.xml <post-migration>, after InitializeSettings (which imports the new schema)
Version: appinfo/info.xml <version> moves, so occ upgrade runs it
Key operations:
- page through lesson objects (register learniq, schema lesson, _rbac false)
- per lesson with a teacherNote block: split, create missing notes, then save the lesson without notes
```

## Migration Steps
1. Read lessons 200 at a time, at most 1000 pages.
2. Skip a lesson whose blocks hold no `teacherNote`.
3. Split the blocks (TeacherNoteSplitter).
4. List the lesson's existing notes; create each note whose `blockId` is not among them, with the lesson's `tenant_id`.
5. When every note exists, save the lesson with the learner blocks only. Otherwise leave the lesson as it was and log its id.
6. Report counts: lessons moved, notes created, lessons left for the next run.

## Data Impact
Only lessons that hold a note are rewritten, and only their `blocks`. No data is deleted before
its copy exists. Safe on live data: the composer on the new version already reads notes from
the new schema, and the player never showed them.

## Rollback Procedure
Revert the PR. Notes remain as `lesson-teacher-note` objects; an old composer does not show
them, and learners still cannot read them. No reverse move is needed for safety.

## Validation
- The repair output reports zero lessons left.
- `ObjectService::findAll(['filters' => ['register' => 'learniq', 'schema' => 'lesson']])` holds no block with `type: teacherNote`.
- `MoveTeacherNotesOutOfLessonsTest` covers the move, the rerun after a partial failure, and a failed create.

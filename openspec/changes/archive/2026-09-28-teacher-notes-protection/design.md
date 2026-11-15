# Design: teacher-notes-protection

## Architecture Overview

```
                 before                                       after
Lesson.blocks  [A, teacherNote N, B]            Lesson.blocks        [A, B]            read: authenticated
                                                LessonTeacherNote    {lessonId, blockId N,
                                                                      afterBlockId A, position 0,
                                                                      text}              read: staff only

LessonComposer  load lesson ──► blocks (+ notes inline)      load lesson + list notes ──► mergeTeacherNotes()
                save PATCH blocks (notes included)           save PATCH lesson (splitTeacherNotes().blocks)
                                                             then create / update / delete notes by blockId
LessonPlayer    playerVisibleBlocks() filters notes           unchanged (defence in depth)
Onboarding      build() → blocks with notes → lesson          build() → TeacherNoteSplitter → lesson + notes
Upgrade         -                                              MoveTeacherNotesOutOfLessons (repair, once per note)
```

## Decisions

### D1: A separate schema, not a property rule
OpenRegister's `x-property-rbac` hides whole properties; `Lesson.blocks` is one array mixing
learner content and notes, so a property rule would hide the lesson body from learners too.
A projection that strips notes on read would have to sit on every read path OpenRegister
exposes (objects API, search, exports, GraphQL, MCP), which learniq does not own. A schema
with its own `authorization` block is enforced by OpenRegister on all of them, the same way
`ItemStatistics` and `ConfidentialNote` are staff-only today.

### D2: Anchor by block id, order by position
A note carries `afterBlockId` (the lesson block it follows; empty means before the first
block) and `position` (its order among notes at the same anchor). Block ids are stable in
learniq: the composer and the draft builder generate them once and never renumber them,
while `order` is renumbered on every save. A note whose anchor was deleted is shown first,
so no note disappears from the composer.

### D3: The note's identity is its `blockId`
A note keeps the `blockId` it had as a block (or a new client uuid). The composer matches
loaded and edited notes by `blockId` to decide create, update or delete, and the upgrade
step skips a note whose `lessonId` and `blockId` already exist, which makes the move
idempotent without a marker.

### D4: One pure splitter serves the importer and the upgrade
`TeacherNoteSplitter::split(array $blocks): array{blocks, notes}` takes the block list as the
draft builder or an old lesson has it and returns the learner blocks plus the notes with
their anchors. It has no dependencies, so the importer and the repair step share one
tested rule. `src/utils/lessonBlocks.js` has the same rule for the composer
(`splitTeacherNotes`, `mergeTeacherNotes`), tested with `node --test`.

### D5: Create the note first, strip the lesson after
The upgrade step creates the missing notes of a lesson, and only when every create
succeeded does it save the lesson without its note blocks. A failure in between leaves the
note in both places for one run; the next run finds the note by `lessonId` + `blockId`,
creates nothing and strips the lesson. It never deletes a note block whose note it could not
create. It runs as a system operation (`_rbac: false`) because it runs from `occ upgrade`
without a user.

### D6: Staff groups
Read: `instructors`, `team-leads`, `coordinators`, `hr`, `compliance-officers`,
`administration-managers` (the teaching and training staff; `hr` and `compliance-officers`
author corporate training lessons). Create, update, delete: `instructors`, `hr`,
`compliance-officers`, `team-leads`, exactly the groups that may create and update a
`Lesson`. No `authenticated` entry of any kind: a note is not about the reader, so there is
no self-match.

### Mixed-spec rationale
The register change and the code change must land together. Dropping `teacherNote` from the
enum without moving the composer makes every save of a lesson with a note fail; adding the
note schema without moving the composer and the importer leaves notes readable by learners.
The config part is one new schema, one enum value removed and the version bumps.

### Declarative-vs-imperative decision
- Access control: declarative, the `authorization` block on `LessonTeacherNote`.
- Moving existing notes: imperative repair step. ADR-031 exception: scheduled bulk work on
  install and upgrade (ADR-106), not a derived field.
- Composer split and merge: presentation logic in the browser, no register behaviour.

## Database Changes
None. OpenRegister stores the new schema's objects in its own tables.

## Nextcloud Integration
- Repair: `MoveTeacherNotesOutOfLessons` (`IRepairStep`; `ObjectService`, `LoggerInterface`),
  registered in `<post-migration>` after `InitializeSettings`, which imports the new schema.
- Services: `TeacherNoteSplitter` (pure), `LessonOnboardingImporter` (uses it).
- OpenRegister: `ObjectService::findAll()` / `saveObject()` with `_rbac: false` in the repair
  step; the objects API from the composer.

## Security Considerations
- The leak closes on the server: learners get no read rule on notes, and the lesson a learner
  reads cannot hold a note because OpenRegister refuses to store one.
- Nextcloud admins bypass OpenRegister's rules, as for every schema; that is unchanged.
- Notes no longer travel in course exports or store packages, which read lesson objects only.
- The repair step logs lesson ids and counts, never note text.

## NL Design System
The composer keeps its existing note block markup and label; no new components or colours.

## File Structure
```
lib/Settings/learniq_register.json                      LessonTeacherNote, Lesson enum, versions
lib/Settings/learniq_mock_register.json                 three mock notes (generated, --keep)
lib/Service/LessonOnboarding/TeacherNoteSplitter.php    new, pure
lib/Service/LessonOnboarding/LessonOnboardingImporter.php  split, write notes after the final blocks
lib/Repair/MoveTeacherNotesOutOfLessons.php             new
appinfo/info.xml                                        repair step, <version>
src/utils/lessonBlocks.js                               splitTeacherNotes, mergeTeacherNotes, diffTeacherNotes
src/views/LessonComposer.vue                            load and save notes separately
```

## Seed Data

### Schema: `lesson-teacher-note`
| Field | Seed (x-openregister-seed) | Mock 1 | Mock 2 |
|-------|------|--------|--------|
| lessonId | nil-prefixed demo lesson uuid | generated uuid | generated uuid |
| blockId | `voorbeeld-notitie-1` | generated | generated |
| afterBlockId | empty (before the first block) | generated | generated |
| position | 0 | 0 | 1 |
| text | "Voorbeeldnotitie: begin met de vraag wat de klas al weet over het onderwerp." | Voorbeeld text | Voorbeeld text |
| tenant_id | `00000000-0000-0000-0000-000000000001` | generated | generated |

Mock rows come from `generate_mock_register.py --keep`, which derives every value from the
schema, so they validate by construction.

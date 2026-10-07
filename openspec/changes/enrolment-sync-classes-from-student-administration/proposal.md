---
kind: code
---

# Keep classes and enrolments in step with the student administration system

## Why

A school keeps its pupils, classes and class lists in its student administration system (Magister, Somtoday, ParnasSys, Eduarte or Osiris). learniq has its own `Cohort` and `Enrolment`. Today a coordinator types the classes in or imports a file, and from then on every pupil who moves class, joins in November or leaves, is a manual edit in two places. Moodle's most requested enrolment feature, OneRoster (MDL-61534, 22 votes), is exactly this, and Moodle core does not have it.

integriq already carries the four rostering sources (`roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`, archived `integriq-adapter-rostering-imports`) and hands imported records to learniq (`ExchangeRecordsReceivedEvent`, `lib/Service/ExchangeImportLanding.php`). Those sources bring lessons. Nothing brings classes and their members.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `enr-sync-classes-from-school-admin` | Keep classes and enrolments in step with the student administration system. | `no`: one-off file import only; no sync |

## What changes

- A new import kind, `class-roster`, that integriq hands to learniq: per class its external id, name, school year, programme year and members (by ECK iD or ncUserId) and teachers.
- learniq upserts a `Cohort` per class on its external id, and keeps the cohort's `learnerIds` and `teacherIds` equal to the source.
- A pupil who joins a class gets the class's enrolments; a pupil who leaves keeps their history: their open enrolments for that class move to `withdrawn` with the reason "Left the class in the student administration".
- Classes and members that came from the source are read only in learniq for those fields. Local work groups, notes and the report card template stay editable.
- The groups page shows when the last sync ran and how many changes it made; a failed run shows its reason.

## Capabilities

### Modified capabilities

- `enrolment`: ADDED requirements for the class roster sync.

## Impact

- **Register**: `Cohort.externalId`, `externalSource`, `syncedAt`; `Enrolment.source` gains `roster-sync`.
- **Backend**: a `class-roster` branch in `ExchangeImportLanding`; `lib/Service/ClassRosterSync.php`.
- **Frontend**: the sync line on the groups page; read-only source fields on the group page.
- **Cross app**: integriq maps OneRoster `classes` and `enrollments` (and the Magister and Somtoday equivalents) to the `class-roster` records. integriq owns the row; this change is learniq's half and the contract.

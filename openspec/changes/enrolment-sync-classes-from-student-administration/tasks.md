# Tasks: keep classes and enrolments in step with the student administration system

## 1. Register

- [x] 1.1 `Cohort`: add `externalId`, `externalSource`, `syncedAt`. `Enrolment.source`: add `roster-sync`. Bump the register version. Verify: `npm run check:register`.

## 2. Landing

- [x] 2.1 Add a `class-roster` branch to `lib/Service/ExchangeImportLanding.php` that calls `lib/Service/ClassRosterSync.php`. Refusal codes `ROSTER-MISSING-FIELD`, `ROSTER-UNKNOWN-PUPIL`. Verify: PHPUnit on the real `ExchangeRecordsReceivedEvent` shape.
- [x] 2.2 `ClassRosterSync`: upsert on external id; diff members; enrol joiners; withdraw leavers with the reason; stamp `syncedAt`. Validate every written payload against the real schema (Opis). Verify: PHPUnit for new class, joiner, leaver, replay (no changes).
- [x] 2.3 Updating listener refusing manual changes to the source fields of a synced cohort, letting the system landing through. Verify: PHPUnit on the real event.

## 3. Screen

- [x] 3.1 Groepen: the sync line per board `LqGroepen`. Groep: read-only source fields with "uit <bron>" per board `LqGroep`. Verify: `npm run check:manifest`, `npm run check:l10n`.
  - Done: the group page shows the source, the last sync time and its changes in a read-only "Student administration" block (`coh-source`); the source's fields are refused server side (2.3). Sync line built 24 Nov: nextcloud-vue's index header note reads a value from an endpoint (`headerMetaSource`, nextcloud-vue#2338), and the groups list (`Cohorts`) fills `headerMeta` "{source}" from `GET /api/cohorts/sync-line` (`CohortSyncLineController`, `CohortSyncLine`): the newest run among the groups the caller may open (one source, the cohorts stamped in that minute), "Bijgewerkt uit Magister om 07.00, 3 wijzigingen"; no note while no group is synced. Test: `CohortSyncLineTest`. Decision: a failed run's reason stays with integriq's job (learniq holds only the cohorts a run wrote), so the line names the last run that wrote.

## 4. Cross app

- [x] 4.1 Open an integriq issue for mapping OneRoster `classes` and `enrollments` (and Magister, Somtoday) to `class-roster` records, referencing this change and its contract. Record the issue here.
  - ConductionNL/integriq#3888 (24 Nov 2026): the record per class (`externalId`, `externalSource`, name, school year, programme year, `learnerIds`, `teacherIds`) and the refusal codes.

## 5. Close out

- [x] 5.1 Live check: land a mock `class-roster` batch twice, then one with a leaver.
  - Live 25 Nov 2026 on NC 34.0.6 + PostgreSQL 16 + OpenRegister main, vo example set, through `ExchangeImportLandingInterface::land('class-roster', ...)` with integriq's record shape: batch 1 (class `OR-CLASS-3H1` from `mock-sis`, three pupils, plus one record with an unknown pupil) gave 1 accepted, `ROSTER-UNKNOWN-PUPIL` on `learnerIds` for the other, one cohort `planned` with `syncedAt`; the same batch again gave the same answer, still one cohort, `syncChanges` 0. With the group's course set, a joiner got one `active` enrolment with `source: roster-sync`; a batch without him withdrew it with "Left the class in the student administration" (`syncChanges` 1 each time); replays added nothing. A manual rename of the synced group answers 422 "The name of this group comes from the student administration (mock-sis) and cannot be changed in learniq."
- [ ] 5.2 Set row `enr-sync-classes-from-school-admin` to built once integriq's mapping exists, and archive this change.

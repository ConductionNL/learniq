# Tasks: keep classes and enrolments in step with the student administration system

## 1. Register

- [x] 1.1 `Cohort`: add `externalId`, `externalSource`, `syncedAt`. `Enrolment.source`: add `roster-sync`. Bump the register version. Verify: `npm run check:register`.

## 2. Landing

- [x] 2.1 Add a `class-roster` branch to `lib/Service/ExchangeImportLanding.php` that calls `lib/Service/ClassRosterSync.php`. Refusal codes `ROSTER-MISSING-FIELD`, `ROSTER-UNKNOWN-PUPIL`. Verify: PHPUnit on the real `ExchangeRecordsReceivedEvent` shape.
- [x] 2.2 `ClassRosterSync`: upsert on external id; diff members; enrol joiners; withdraw leavers with the reason; stamp `syncedAt`. Validate every written payload against the real schema (Opis). Verify: PHPUnit for new class, joiner, leaver, replay (no changes).
- [x] 2.3 Updating listener refusing manual changes to the source fields of a synced cohort, letting the system landing through. Verify: PHPUnit on the real event.

## 3. Screen

- [ ] 3.1 Groepen: the sync line per board `LqGroepen`. Groep: read-only source fields with "uit <bron>" per board `LqGroep`. Verify: `npm run check:manifest`, `npm run check:l10n`.
  - Done: the group page shows the source, the last sync time and its changes in a read-only "Student administration" block (`coh-source`); the source's fields are refused server side (2.3). Open: the one sync line under the Groepen title and a failed run's reason. The index page's header takes static text or `{total}` only, so the line needs a library key for an aggregate over the cohorts, and a failed run's reason is held by integriq's job, not by learniq.

## 4. Cross app

- [ ] 4.1 Open an integriq issue for mapping OneRoster `classes` and `enrollments` (and Magister, Somtoday) to `class-roster` records, referencing this change and its contract. Record the issue here.

## 5. Close out

- [ ] 5.1 Live check: land a mock `class-roster` batch twice, then one with a leaver.
- [ ] 5.2 Set row `enr-sync-classes-from-school-admin` to built once integriq's mapping exists, and archive this change.

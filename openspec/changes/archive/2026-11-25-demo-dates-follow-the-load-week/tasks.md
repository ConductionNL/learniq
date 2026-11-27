# Tasks: demo-dates-follow-the-load-week

- [x] **T1**: `DemoDates`: offset and the move of ISO dates, date-times, weeks and Dutch phrases
  - PHPUnit `DemoDatesTest`
- [x] **T2**: `ExampleSetDates`: remembered offset per set, the move of existing objects
  - PHPUnit `ExampleSetDatesTest`
- [x] **T3**: `SeedProfileService::install` moves what exists, imports at this week's offset, remembers it; `ExamplePortalProvisioner` and `ExamplePortalContent::moveDates` for pages and news; `occ learniq:example-set:portal` uses the set's offset
  - PHPUnit `SeedProfileServiceTest::testASameWeekReloadMovesNothingThatExists`, `testAReloadInALaterWeekMovesWhatExistsFirst`
- [x] **T4**: board checks date-proof: `dated()` turns a board text with dates into a pattern for any week; the notice strips' lead and text, `nlLinkColumns`, "Verloopt over N weken"
  - `tsc --noEmit`, eslint on `tests/e2e/portal-design/`
- [x] **T4b**: the vo lessons already carry `changeReasonKind` (Economie `room-unavailable`, LO cancelled with `teacher-absence`) since #1756; no seed change
- [x] **T5**: live: a load on :8092 shows today's lessons
  - Live 2026-11-25 on a sandbox instance (Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main, portaliq development), not :8092: `occ learniq:example-set:remove vo --apply`, then `occ learniq:example-set:load vo` on Wednesday 25 November (8202 objects, 0 skipped after #2776) and `occ learniq:example-set:portal vo`. Fenna de Jong (vo-leerling-121) signed in on the vaartveld portal reads "Woensdag 25 november 2026" and "Je rooster vandaag · 7 lesuren · 2 wijzigingen · uit om 14.20 uur": the boards' Wednesday moved to the load week, Frans in the 6th hour "Vervalt" (De docent is afwezig) and the mentor hour "Ander lokaal" 0.10.

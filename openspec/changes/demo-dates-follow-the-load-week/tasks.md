# Tasks: demo-dates-follow-the-load-week

- [x] **T1**: `DemoDates`: offset and the move of ISO dates, date-times, weeks and Dutch phrases
  - PHPUnit `DemoDatesTest`
- [x] **T2**: `ExampleSetDates`: remembered offset per set, the move of existing objects
  - PHPUnit `ExampleSetDatesTest`
- [x] **T3**: `SeedProfileService::install` moves what exists, imports at this week's offset, remembers it; `ExamplePortalProvisioner` and `ExamplePortalContent::moveDates` for pages and news; `occ learniq:example-set:portal` uses the set's offset
  - PHPUnit `SeedProfileServiceTest::testASameWeekReloadMovesNothingThatExists`, `testAReloadInALaterWeekMovesWhatExistsFirst`
- [ ] **T4**: board checks date-proof (vaartveld timetable, academy expiry)
- [ ] **T5**: live: a load on :8092 shows today's lessons

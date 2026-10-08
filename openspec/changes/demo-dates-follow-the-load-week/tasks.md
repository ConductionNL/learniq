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
- [ ] **T5**: live: a load on :8092 shows today's lessons

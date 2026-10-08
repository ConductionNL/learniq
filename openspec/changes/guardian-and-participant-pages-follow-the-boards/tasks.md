# Tasks: guardian-and-participant-pages-follow-the-boards

- [x] **T1**: guardian overview without `records`; task card leaves out a booked round; due day in line; event description under the tile
- [x] **T2**: absence page for both children
  - PHPUnit `GuardianSitePagesTest`
- [x] **T3**: po `/zoeken` searches news; vo leaves `learniq:studentHourWeeks` out of the menu
  - PHPUnit `ExamplePortalDeclarationsTest::testEverySchoolPortalLeavesOutTheCaseItems`
- [x] **T4**: participant overview: next course day once, "Daarna" after it
  - PHPUnit `ParticipantSitePagesTest::testTheNextCourseDayShowsOnce`
- [ ] **T5**: the child's name on the task title and on each absence report (needs a portaliq lookup by a row field)

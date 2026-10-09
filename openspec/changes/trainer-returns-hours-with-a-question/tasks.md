# Tasks: trainer-returns-hours-with-a-question

- [x] **T1**: `bpv-hour-week` copies and description; `HourWeekLabel`; backfill; seeds
  - PHPUnit `ReadableCopyStampTest::testAWeekOfHoursNamesItsStudentAndDays`, `VocationalCollegeExampleSetTest` (generator check)
- [x] **T2**: `returnHourWeek`, the send-back endpoint, both forms on the hours page, the note on the student's page
  - PHPUnit `PortalHourWeekApprovalTest::testAWeekIsSentBackWithAQuestion`, `PortalContributionProviderTest`, `GuardianSitePagesTest::testTheTrainerOverviewAndMenu`
- [x] **T3**: one "Wanneer?" question and an optional note; end day and note stamped
  - PHPUnit `ExcuseRequestOwnerStampTest::testAOneQuestionReportGetsItsEndDayAndNote`, `GuardianSitePagesTest::testTheAbsenceFormUsesCardsAndNamedDays`, `ExcuseRequestRegisterTest`
- [x] **T4**: the pupil reads "je"
  - PHPUnit `PupilWordingTest`
- [ ] **T5**: live: proof run 4

# Tasks: student-portal-reads-like-the-boards

- [x] **T1**: `studentGrades` columns (Subject, Date, Grade)
  - PHPUnit `PortalContributionProviderTest::testStudentGradesHaveReadableColumns`
- [x] **T2**: `studentHourWeeks` in `MENU_PAGES` as "BPV and hours"; l10n "BPV en uren"
  - PHPUnit `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`, `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo`
- [x] **T3**: `HOUR_WEEK_STATUS.rejected` reads "Sent back"
  - PHPUnit `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo`
- [ ] **T4**: live: vaartveld grades and esdoornveen menu on the proof instance

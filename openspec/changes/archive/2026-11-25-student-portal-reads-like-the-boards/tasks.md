# Tasks: student-portal-reads-like-the-boards

- [x] **T1**: `studentGrades` columns (Subject, Date, Grade)
  - PHPUnit `PortalContributionProviderTest::testStudentGradesHaveReadableColumns`
- [x] **T2**: `studentHourWeeks` in `MENU_PAGES` as "BPV and hours"; l10n "BPV en uren"
  - PHPUnit `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`, `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo`
- [x] **T3**: `HOUR_WEEK_STATUS.rejected` reads "Sent back"
  - PHPUnit `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo`
- [x] **T4**: live: vaartveld grades and esdoornveen menu on the proof instance
  - Live 2026-11-25 on a sandbox instance (Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main, portaliq development; vo and mbo sets loaded that week), not the proof instance: Fenna de Jong's Cijfers on vaartveld read "Vak · Datum · Cijfer" rows such as "Engels 20-11-2026 6,9", no field key; Milan de Groot's esdoornveen menu lists "BPV en uren" between Cijfers and Afwezig melden, and its Mijn uren table shows the week Petra sent back (2026-W47 after the date move) as "Teruggestuurd".

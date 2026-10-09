# Tasks: vaartveld-pupil-pages-follow-the-boards

- [x] **T1**: overview: greeting with week, columns, frames, `more` links, homework and grades as rows, absence strip; no buttons, no messages
  - PHPUnit `PupilPagesFollowTheBoardsTest::testTheOverviewHasTheBoardsColumnsAndStrip`, `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`
- [x] **T2**: grades page grouped per subject with summary and tabs
  - PHPUnit `PupilPagesFollowTheBoardsTest::testTheGradesAreGroupedPerSubject`
- [x] **T3**: Dutch words; the translator reads `stripLabel` and `summaryText` and keeps tab values
  - PHPUnit `PupilPagesFollowTheBoardsTest::testTheBoardWordsArriveInDutch`, `PortalLabelTranslatorTest`
- [ ] **T4**: FIX-L: the fields listed under "Not in this change"
- [ ] **T5**: live: Noor's overview and grades on the proof instance once PQ-MIJN is merged

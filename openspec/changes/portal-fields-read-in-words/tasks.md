# Tasks: portal-fields-read-in-words

- [x] **T1**: `PortalFieldWords`, applied in `PortalContributionProvider::getContribution()`
  - PHPUnit `PortalFieldWordsTest::testEveryReadFieldHasALabelAndEveryValueWords`, `testDeclaredWordsWinAndTheSchemaFillsTheRest`
- [x] **T2**: attendance reads minutes late; dates without seconds on attendance and conference rounds
  - PHPUnit `PortalFieldWordsTest::testAttendanceReadsMinutesLate`, `PortalContributionProviderTest`
- [ ] **T3**: live: proof run 4

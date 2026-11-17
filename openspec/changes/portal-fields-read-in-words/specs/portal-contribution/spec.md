## ADDED Requirements

### Requirement: Every field a portal reader sees reads in words

Every field a portal collection projects, other than references the portal leaves out of a cell, MUST carry a label from learniq's catalogue. Every field with a fixed set of values MUST carry words for each value, also from the catalogue. A label or value labels a declaration sets itself MUST be kept.

#### Scenario: Fatima reads Sami's absence reports
- **GIVEN** Sami's report of kind `illness` in state `submitted`
- **WHEN** Fatima opens Afwezigheid in Dutch
- **THEN** the report reads "Ziekte" and "Ingediend", never `illness` or `submitted`
- @e2e exclude covered by PHPUnit `PortalFieldWordsTest::testEveryReadFieldHasALabelAndEveryValueWords`

#### Scenario: A late arrival
- **GIVEN** Vera came in 10 minutes late
- **WHEN** her attendance is listed
- **THEN** the row reads 10 minutes late, not 335 minutes present
- @e2e exclude covered by PHPUnit `PortalFieldWordsTest::testAttendanceReadsMinutesLate`

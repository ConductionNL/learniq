## ADDED Requirements

### Requirement: An absence is reported with one question and an optional note

A guardian MUST be able to report an absence with one "Wanneer?" question and without a note. A report without an end day MUST cover its first day only, and a report without a note MUST get the words of its kind.

#### Scenario: Sami is ill today
- **GIVEN** Fatima reports Sami ill for today and writes no note
- **WHEN** the report is stored
- **THEN** it runs from today to today and its note reads "Ziek"
- @e2e exclude covered by PHPUnit `ExcuseRequestOwnerStampTest::testAOneQuestionReportGetsItsEndDayAndNote`

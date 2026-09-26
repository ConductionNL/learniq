# Report Card Specification

## ADDED Requirements

### Requirement: ReportPeriod declares holidays and study days
`ReportPeriod` MUST declare `holidays` (array of `{ name, startDate, endDate }`, default `[]`) and `studyDays` (array of `{ date, description }`, default `[]`) additively.

#### Scenario: A school year period records its holidays and study days
- **GIVEN** a `ReportPeriod`
- **WHEN** `holidays` is set with a named date range and `studyDays` is set with individual dates
- **THEN** both persist on the `ReportPeriod` object

#### Scenario: A pre-existing ReportPeriod without holidays or study days is unaffected
- **GIVEN** a pre-existing `ReportPeriod` row with neither field set
- **WHEN** it is read
- **THEN** both resolve to empty arrays and the existing `startDate`/`endDate`/`periodCode` fields are unchanged

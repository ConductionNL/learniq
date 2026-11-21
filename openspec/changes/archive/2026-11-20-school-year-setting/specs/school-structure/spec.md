# school-structure Delta: school-year-setting

## ADDED Requirements

### Requirement: The school year starts on the day the school sets

The school year SHALL start on the day in the app setting `school_year_start` (`MM-DD`), and on 1 August when the
setting is missing or not a valid day. A date SHALL fall in the school year that started on the last such day on or
before it; the year is written `YYYY-YYYY` after the calendar years it spans, and ends the day before the next start.

#### Scenario: A school that starts in September

- **GIVEN** `school_year_start` is `09-01`
- **WHEN** the contact hours of 20 August 2027 are counted
- **THEN** that day falls in school year 2026-2027, which runs from 1 September 2026 to 31 August 2027

#### Scenario: No setting

- **GIVEN** no `school_year_start`
- **WHEN** a standby slot is planned on 21 October 2026 without validity dates
- **THEN** it is valid from 1 August 2026 to 31 July 2027

## ADDED Requirements

### Requirement: The guardian reads that the school saw her report

The parent audience's absence collection MUST project a line naming the teacher and the time the report was seen while it is `submitted`, and MUST offer the action `reportRecovered` on a report of her own child without a last day.

#### Scenario: Seen at 8.12
- **GIVEN** juf Esra saw Sami's report at 8.12
- **WHEN** Fatima opens her absence page
- **THEN** the report reads "Gezien door juf Esra om 8.12 uur"
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

### Requirement: A pupil of eighteen reports for herself

The student audience's absence action MUST accept lesson hours and an open last day for a learner of eighteen or older, and MUST offer `reportRecovered` on her own open report.

#### Scenario: Eighteen and ill
- **GIVEN** a vwo 6 pupil of eighteen, signed in with her school account
- **WHEN** she reports herself ill without a last day
- **THEN** the report names her as the one who reported, and she can end it herself
- @e2e exclude spec-only proposal; asserted in the action's unit tests

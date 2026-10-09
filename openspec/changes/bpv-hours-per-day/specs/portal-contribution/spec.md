## ADDED Requirements

### Requirement: A student writes hours per day and keeps a draft

The student audience MUST offer `writeHourDay`, which adds or replaces the day line of her own week that holds the date (creating that week as a draft when absent), and MUST let her keep the week as a draft or submit it. Her overview MUST name a placement workday of today without a day line. She MUST be able to set her own estimate on her own `werkproces-progress` rows.

#### Scenario: Kept for later
- **GIVEN** Milan writes Monday and chooses "Bewaren en later versturen"
- **WHEN** Petra opens her hours to approve
- **THEN** week 41 is not among them, and Milan's week reads as a draft
- @e2e tests/e2e/portal-design/esdoornveen.spec.ts

#### Scenario: Not her own work process
- **GIVEN** a `werkproces-progress` row of another student
- **WHEN** Milan sets an estimate on it
- **THEN** it is refused and nothing changes
- @e2e exclude refusal asserted in the action's unit tests

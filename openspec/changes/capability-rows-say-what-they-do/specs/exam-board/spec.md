## ADDED Requirements

### Requirement: A fraud case moves from report to a scheduled and held hearing

A `FraudCase` MUST start in `reported`. Staff MUST be able to schedule the hearing, which MUST be refused without a `hearingDate`, and then record that the hearing was held, moving the case to `heard` so it can be decided.

#### Scenario: A hearing is scheduled and held

- **GIVEN** a fraud case in `reported`
- **WHEN** an exam board member schedules the hearing for 14 March and later records that it was held
- **THEN** the case moves to `hearing-scheduled` with that `hearingDate`, and then to `heard`

#### Scenario: A hearing without a date

- **GIVEN** a fraud case in `reported`
- **WHEN** an exam board member schedules the hearing without a date
- **THEN** the transition is refused with a message that the hearing needs a date

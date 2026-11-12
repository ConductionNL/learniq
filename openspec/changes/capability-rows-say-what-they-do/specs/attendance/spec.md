## ADDED Requirements

### Requirement: A reported attendance flag is not closed until the municipality's feedback is recorded

An `AttendanceFlag` in `reported` MUST NOT move to `resolved` until `municipalityFeedback` holds the municipality's case route. The refusal MUST say that the municipality's answer is still missing.

#### Scenario: Closing a flag without the municipality's answer

- **GIVEN** an attendance flag in `reported` with no `municipalityFeedback`
- **WHEN** a coordinator resolves the flag
- **THEN** the transition is refused with the reason that the municipality's answer is not recorded yet
- **AND** the flag stays `reported`

#### Scenario: Closing a flag after the municipality answered

- **GIVEN** a reported flag on which a coordinator recorded the MAS route "casusoverleg"
- **WHEN** the coordinator resolves the flag
- **THEN** the flag moves to `resolved`

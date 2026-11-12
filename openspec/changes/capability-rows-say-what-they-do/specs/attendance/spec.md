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

### Requirement: A school sets its own absence limit next to the statutory one

A coordinator or administrator MUST be able to create and change an `AttendanceThreshold` with its own `limit`, `metric`, `window` and `scope`, for example a lower early-warning limit next to the statutory 16 hours. Each active threshold MUST be checked on its own, so a learner can cross the school's own limit while still below the statutory one. A change to a threshold MUST be kept in its history.

#### Scenario: A school warns at eight hours

- **GIVEN** the statutory threshold of 16 unexcused lesson hours in a rolling four weeks
- **WHEN** a coordinator adds an active threshold "Vroeg signaal bij verzuim" of 8 unexcused lesson hours in a rolling four weeks
- **THEN** a learner with 9 unexcused lesson hours in that window gets a flag from the school's own threshold
- **AND** no report goes to the authority, because the statutory threshold is not crossed

#### Scenario: The school lowers its limit

- **GIVEN** the school's own threshold at 10 lesson hours
- **WHEN** a coordinator changes it to 8 and saves
- **THEN** the threshold checks against 8 from then on, and its history shows the change with who made it

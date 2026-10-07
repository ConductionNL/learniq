## ADDED Requirements

### Requirement: A flag is handled from its own page

`AttendanceFlagDetail` MUST show the transitions OpenRegister allows the caller on the flag's current state, in the page header. The flag's fields MUST stay read only. A user in `instructors` or `compliance-officers` MUST be able to take up an open flag, mark an in-handling flag as reported and close a flag, without using the API.

#### Scenario: A mentor takes up an open flag

- **GIVEN** an attendance flag in state `open` and a user in `instructors`
- **WHEN** the user opens the flag and chooses "Take up"
- **THEN** the flag's lifecycle is `in-handling`
- **AND** the exchange job linked to the flag is no longer held by the exchange gate

#### Scenario: A closed flag offers nothing

- **GIVEN** an attendance flag in state `resolved`
- **WHEN** a user in `instructors` opens it
- **THEN** the header shows no transition

#### Scenario: The fields stay read only

- **GIVEN** an attendance flag in any state
- **WHEN** a user in `compliance-officers` opens it
- **THEN** the page offers no edit of the flag's fields

### Requirement: Marking a flag as reported waits for the exchange

The "Mark as reported" action MUST succeed only when `AttendanceFlagReportGuard` allows it. When the guard refuses, the page MUST show the refusal reason and leave the flag in `in-handling`. The page MUST show the linked exchange job and its status.

#### Scenario: The exchange has succeeded

- **GIVEN** a flag in `in-handling` whose linked integriq job has `exchangeStatus` `succeeded`
- **WHEN** the user chooses "Mark as reported"
- **THEN** the flag's lifecycle is `reported`

#### Scenario: The exchange is still running

- **GIVEN** a flag in `in-handling` whose linked job has `exchangeStatus` `running`
- **WHEN** the user chooses "Mark as reported"
- **THEN** the flag stays `in-handling`
- **AND** the page shows the guard's reason

#### Scenario: No exchange is linked

- **GIVEN** a flag in `in-handling` without a `dataExchangeJobId`
- **WHEN** the user chooses "Mark as reported"
- **THEN** the flag's lifecycle is `reported`

### Requirement: The municipality's answer is recorded from the page

On a `reported` flag the page MUST offer "Record the municipality's answer", which asks for the MAS route and a note and runs `recordMunicipalityFeedback`. The dialog MUST NOT ask for `receivedAt` or `recordedBy`.

#### Scenario: A coordinator records the MAS route

- **GIVEN** a flag in `reported`
- **WHEN** a user in `compliance-officers` chooses "Record the municipality's answer", types a MAS route and saves
- **THEN** `municipalityFeedback.masRoute` holds that route
- **AND** `municipalityFeedback.recordedBy` is that user and the flag stays `reported`

### Requirement: The flags list shows where each flag stands

The `AttendanceFlags` list MUST show the lifecycle as a column and MUST let the user filter on it.

#### Scenario: Filter on open flags

- **GIVEN** flags in `open`, `in-handling` and `resolved`
- **WHEN** the user filters the list on `open`
- **THEN** only the open flags are listed

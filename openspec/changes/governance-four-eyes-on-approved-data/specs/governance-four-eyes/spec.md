## ADDED Requirements

### Requirement: Second approver for changes to approved data

The system MUST refuse a publish or republish of a grade entry in a locked report period unless an approved `DataCorrectionRequest` for that grade entry exists whose approver differs from its requester and from the person who publishes. The role override MUST no longer be sufficient on its own.

#### Scenario: A principal cannot override alone

- **GIVEN** a locked report period and a principal who tries to republish a grade entry
- **WHEN** the republish is attempted without a correction request
- **THEN** the transition is denied and the message says a second approver is required

#### Scenario: A second person approves a correction

- **GIVEN** a correction request from teacher A for a grade entry
- **WHEN** principal B approves it and teacher A republishes
- **THEN** the republish succeeds and the entry records both users

#### Scenario: The requester cannot approve

- **GIVEN** a correction request by teacher A
- **WHEN** teacher A approves it
- **THEN** the approval is denied

### Requirement: Audit of corrections

Every applied correction MUST keep the requester, the approver, the reason and the before and after values in the object's audit trail.

#### Scenario: An auditor reads the trail

- **GIVEN** an applied correction
- **WHEN** an auditor opens the grade entry history
- **THEN** the entry shows requester, approver, reason and the changed value

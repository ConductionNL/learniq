# AVG register: reuse OpenRegister's data subject request register (D20)

## ADDED Requirements

### Requirement: Privacy requests live in OpenRegister's data subject request register

Learniq MUST NOT ship its own data subject request schema. The privacy request index and detail pages MUST read OpenRegister's `data-subject-requests` register, schema `dataSubjectRequest`, with `status` as the lifecycle field. On upgrade, a repair step MUST copy every existing learniq `data-subject-request` row into that register: `kind` correction becomes `type` rectification, deletion becomes erasure; the learniq lifecycle maps requested → received, in-review → in-progress, completed → fulfilled, rejected → refused; `learnerId` becomes `subjectId`, `requestedAt` becomes `receivedAt`, `submittedBy` becomes `handler`; the description and audit trail are kept in `notes`. The copy MUST be idempotent and MUST NOT delete the source rows.

#### Scenario: A completed deletion request is moved

- **GIVEN** a learniq request with kind `deletion`, state `completed`, requested on 2026-03-01 for `leerling-001`
- **WHEN** the upgrade runs
- **THEN** OpenRegister holds a `dataSubjectRequest` case with type `erasure`, status `fulfilled`, received 2026-03-01, subject `leerling-001`
- **AND** its notes start with a line naming the learniq request

#### Scenario: A second upgrade does not duplicate cases

- **GIVEN** the requests were already moved
- **WHEN** the upgrade runs again
- **THEN** no new case is created

#### Scenario: The index reads OpenRegister's register

- **GIVEN** a compliance officer opens Privacy requests
- **WHEN** the page loads
- **THEN** it lists `dataSubjectRequest` cases from the `data-subject-requests` register

### Requirement: The privacy governance overview is a typed dashboard page

The privacy governance page MUST be a `type: "dashboard"` manifest page with no custom component. Its tiles MUST read `PrivacyGovernanceController::overview()` through `endpointSource`: two-factor adoption (with the eligible count in the caption), partner approvals pending, approved and rejected, and a table of the governance groups. A figure the endpoint reports as `null` MUST show as "unknown", never as 0.

#### Scenario: A compliance officer opens the overview

- **GIVEN** the governance groups exist and two-factor authentication is registered
- **WHEN** a compliance officer opens Privacy governance
- **THEN** four tiles and the group table show the figures from the overview endpoint

#### Scenario: Two-factor adoption is unknown

- **GIVEN** no two-factor provider is registered
- **WHEN** the overview loads
- **THEN** the two-factor tile shows "unknown"

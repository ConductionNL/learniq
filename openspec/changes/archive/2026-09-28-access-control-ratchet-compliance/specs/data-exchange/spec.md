# Data exchange: import record access delta

## ADDED Requirements

### Requirement: Imported LVS results and transfer dossiers are read and written by the groups that review them

`LvsResult` and `OsoImportDossier` MUST each carry an `authorization` block that OpenRegister enforces. Read MUST be granted to `coordinators` and `compliance-officers`, and on `LvsResult` also to the learner the row is about, as `{"group": "authenticated", "match": {"learnerId": "$userId"}}`. `OsoImportDossier` MUST NOT carry a learner self-read, because its `learnerEckId` is not a Nextcloud user. Create and update MUST be granted to `coordinators` and `compliance-officers` only, and neither block MAY grant delete. The `verify` guard of `LvsResult` and the `accept` and `reject` guards of `OsoImportDossier` MUST authorise `admin` and `coordinators`, the group the register declares, and MUST refuse any other group, including a group literally named `coordinator`. `LvsResult` MUST NOT be `appendOnly`, so `verify` and `archive` can run.

#### Scenario: A coordinator verifies an imported LVS result
@e2e exclude Enforced by OpenRegister from the shipped register JSON and the guard; pinned by tests/Unit/Register/ImportRecordAccessTest.php and tests/Unit/Lifecycle/LvsResultVerifyGuardTest.php.
- **GIVEN** an `LvsResult` in `imported`
- **AND** a user in `coordinators`
- **WHEN** the user fires `verify`
- **THEN** the result moves to `verified`

#### Scenario: A pupil reads their own LVS result and not a classmate's
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/ImportRecordAccessTest.php and tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **GIVEN** pupils A and B, in no staff group, each with an `LvsResult`
- **WHEN** pupil A lists LVS results
- **THEN** only A's own result is returned

#### Scenario: An instructor no longer reads transfer dossiers
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/ImportRecordAccessTest.php.
- **GIVEN** a received `OsoImportDossier`
- **AND** a user in `instructors` only
- **WHEN** the user lists transfer dossiers
- **THEN** the dossier is not returned

#### Scenario: The singular coordinator group accepts nothing
@e2e exclude Guard behaviour with no UI of its own; pinned by tests/Unit/Lifecycle/OsoImportAcceptGuardTest.php.
- **GIVEN** an `OsoImportDossier` in `under-review`
- **AND** a user in a group named `coordinator`, which the register does not declare
- **WHEN** the user fires `accept`
- **THEN** the guard refuses it

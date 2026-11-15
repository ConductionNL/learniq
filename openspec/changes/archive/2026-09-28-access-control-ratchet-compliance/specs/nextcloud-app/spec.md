# Nextcloud app: declared audience delta

## MODIFIED Requirements

### Requirement: A schema's declared audience is enforced by its authorization block
Every schema that declares who may read its rows in `x-property-rbac` MUST carry an `authorization` block that OpenRegister enforces and that grants read to that audience, because OpenRegister does not read `x-property-rbac` (openregister#4064). A rule of the form "the person in field F reads this row" MUST be enforced as an `authenticated` entry matching F against the caller. Role words MUST map onto the declared groups: teacher to `instructors`, `team-leads`, `coordinators` and `administration-managers`; mentor and study adviser to `team-leads`; coordinator to `coordinators`; principal and finance to `administration-managers`; exam board to `compliance-officers`; manager to `team-leads` and `administration-managers`; admin to OpenRegister's admin bypass. Schemas that relied on the register cascade MUST keep the create and update grants the cascade gave them, except where a requirement of their own capability names the writers: the data-exchange import records `LvsResult` and `OsoImportDossier` are written by `coordinators` and `compliance-officers`, the groups that review them. The group a schema's transition guard authorises MUST also read that schema, since it has to find the row it acts on.

#### Scenario: A learner reads their own grade and not a classmate's
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **GIVEN** learner A and learner B, neither in a staff group, each with a GradeEntry
- **WHEN** learner B lists grade entries
- **THEN** only B's own grade entry is returned

#### Scenario: A schema cannot ship an unenforced audience
@e2e exclude Register-content invariant with no UI; pinned by tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **WHEN** a schema carries `x-property-rbac` without an `authorization` block
- **THEN** the unit suite fails and names the schema

#### Scenario: A coordinator finds the transfer dossier they must review
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/ImportRecordAccessTest.php.
- **GIVEN** an `OsoImportDossier` in `under-review`, whose `accept` guard authorises `coordinators`
- **WHEN** a user in `coordinators` lists transfer dossiers
- **THEN** the dossier is returned

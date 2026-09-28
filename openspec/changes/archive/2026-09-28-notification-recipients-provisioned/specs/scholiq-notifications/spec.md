# Notifications: provisioned recipients delta

## ADDED Requirements

### Requirement: Group recipients MUST be groups an install provisions and that can read the object

Every `kind: groups` recipient of an `x-openregister-notifications` rule MUST name only groups the register declares as oauth2 scopes in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`, or `admin`. Where the schema declares `authorization.read` without `authenticated`, every recipient group other than `admin` MUST be on that list. Role words such as `coordinator`, `mentor`, `examboard`, `exam-board`, `study-advisor` and `compliance-officer` MUST NOT be used as group names.

#### Scenario: A timetable conflict reaches the coordinators
@e2e exclude Register-JSON recipient mapping with no DOM surface; pinned by tests/Unit/Register/NotificationRecipientGroupsAreDeclaredTest.php::testEveryGroupRecipientIsADeclaredGroup.
- **GIVEN** the `TimetableConflict.conflictDetected` rule
- **WHEN** a conflict is created
- **THEN** the rule's group recipient is `coordinators`, a declared group

#### Scenario: A fraud report reaches a group that can open it
@e2e exclude Register-JSON recipient mapping with no DOM surface; pinned by tests/Unit/Register/NotificationRecipientGroupsAreDeclaredTest.php::testEveryGroupRecipientCanReadTheObject.
- **GIVEN** `FraudCase` is read by `instructors` and `compliance-officers`
- **WHEN** the `reported` rule fires
- **THEN** it notifies `compliance-officers`, not the undeclared `examboard`

# Pupil dossier: first-aid incident access delta

## ADDED Requirements

### Requirement: A first-aid incident is read by its reporter and runs its lifecycle

`FirstAidIncident.authorization.read` MUST grant the staff member who reported the incident, as `{"group": "authenticated", "match": {"reportedBy": "$userId"}}`, next to its `instructors` and `compliance-officers` entries. Its create and update grants MUST stay `instructors` and `compliance-officers`. `FirstAidIncident` MUST NOT be `appendOnly`, because Open Register runs `startHandling` and `resolve` as updates and refuses every update on an append-only schema. Each version of the incident stays in Open Register's audit trail.

#### Scenario: The reporter reads the incident they recorded
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/DeclaredAudienceEnforcedTest.php (testEverySelfMatchIsEnforced).
- **GIVEN** a `FirstAidIncident` with `reportedBy: "medewerker-007"`
- **AND** `medewerker-007` is in no staff group
- **WHEN** `medewerker-007` opens the incident
- **THEN** the incident is returned

#### Scenario: An open incident moves to in-handling
@e2e exclude Register-content invariant; pinned by tests/Unit/Register/LifecycleSchemasAreNotAppendOnlyTest.php.
- **GIVEN** a `FirstAidIncident` in `open`
- **WHEN** an instructor fires `startHandling`
- **THEN** the incident lands in `in-handling` instead of being refused as an update on an append-only schema

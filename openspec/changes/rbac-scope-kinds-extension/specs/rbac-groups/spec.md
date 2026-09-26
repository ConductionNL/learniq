## ADDED Requirements

### Requirement: An own-group scope kind grants read access via a caller's Nextcloud group membership
`Cohort` SHALL declare an explicit `authorization` block reproducing the register cascade's `read`/`create`/`update` grantees
(`instructors`, `hr`, `compliance-officers`, `team-leads`) plus one additional conditional `read` entry:
`{group: "authenticated", match: {ncGroupId: {"$in": "$user.groups"}}}`. Any authenticated caller who is a member of the
Nextcloud group named by that specific `Cohort` object's own `ncGroupId` property SHALL be granted read, independently of
holding any staff role. This SHALL NOT narrow any grant the register cascade already provided.

#### Scenario: A non-staff member of the cohort's own Nextcloud group can read it
- **GIVEN** a `Cohort` with `ncGroupId: "cohort-7a-2026"`
- **AND** a caller who is a member of the Nextcloud group `cohort-7a-2026` but not of `instructors`/`hr`/`compliance-officers`/`team-leads`
- **WHEN** the caller reads that `Cohort`
- **THEN** access is granted via the own-group conditional entry

#### Scenario: Staff access is unchanged
- **GIVEN** a caller in the `instructors` group
- **WHEN** they read, create, or update any `Cohort`
- **THEN** access is granted exactly as before this change

<!-- @e2e exclude Declarative RBAC condition, verified by RbacScopeKindsRegisterTest asserting the exact authorization shape; enforcement itself runs in OpenRegister core (ConditionMatcher/OperatorEvaluator), not learniq PHP. -->

### Requirement: A care-team scope kind grants read access via an array-of-user-ids property
`DossierNote` SHALL gain a `careTeamUserIds` property (array of Nextcloud user ids, default `[]`) and one additional
conditional entry appended to its existing `authorization.read` array: `{group: "authenticated", match: {careTeamUserIds:
{"$contains": "$userId"}}}`. Any Nextcloud user id named in a note's `careTeamUserIds` SHALL be granted read, additive to
the existing instructors/compliance-officers/author grants. `create` and `update` SHALL remain unchanged (staff-only).

#### Scenario: A named care-team member can read a note they did not author
- **GIVEN** a `DossierNote` authored by `mentor-1` with `careTeamUserIds: ["coordinator-3"]`
- **WHEN** `coordinator-3` reads the note
- **THEN** access is granted via the care-team conditional entry, even though `coordinator-3` is neither the author nor
  necessarily in the `instructors`/`compliance-officers` groups

#### Scenario: An uninvolved staff member outside the existing floor is still denied
- **GIVEN** the same note
- **WHEN** a caller who is not in `instructors`/`compliance-officers`, is not the author, and is not named in
  `careTeamUserIds` attempts to read it
- **THEN** access is denied

<!-- @e2e exclude Declarative RBAC condition, verified by RbacScopeKindsRegisterTest asserting the exact authorization shape and the new property's default; enforcement runs in OpenRegister core. -->

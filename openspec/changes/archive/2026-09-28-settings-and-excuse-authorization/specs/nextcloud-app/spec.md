# Nextcloud app: settings access delta

## ADDED Requirements

### Requirement: Only administration managers and admins change the organisation's segment

`LearniqSettings` MUST carry an `authorization` block. Read MUST be granted to the staff groups (`instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`, `confidential-counsellors`). Create and update MUST be granted to `administration-managers` only; admins pass OpenRegister's admin bypass. The block MUST NOT grant delete, and MUST NOT grant anything to `learners` or `guardians`.

#### Scenario: An instructor cannot change the segment
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php (testOnlyAdministrationManagersChangeTheSegment).
- **GIVEN** the `LearniqSettings` row with segment `po`
- **AND** a user in `instructors` only
- **WHEN** the user updates the segment to `corporate`
- **THEN** OpenRegister refuses the update
- **AND** the user can still read the row

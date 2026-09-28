# confidential-counsel Specification

## Purpose
Give a school's confidential counsellor (vertrouwenspersoon) a place for case notes that no school leader, mentor, coordinator or compliance officer can open. One declared scope, `confidential-counsellors`, and one isolated schema, `ConfidentialNote`, readable only by its author and the people the author names on the case, with no link to or from the pupil record.

## Requirements

### Requirement: The confidential counsellor has one declared scope
The register MUST declare the scope `confidential-counsellors` next to the eight existing groups, so OpenRegister provisions it. `DashboardRoleService::GROUP_BACKED_ROLES` MUST map the role `confidential-counsellor` to it, ranked below every staff role and above `guardian`.

#### Scenario: A teacher who is also the vertrouwenspersoon keeps the teacher view
- **GIVEN** a user in `instructors` and in `confidential-counsellors`
- **WHEN** the primary role is resolved
- **THEN** it is `instructor`, and `isConfidentialCounsellor()` is true

#### Scenario: An external vertrouwenspersoon is not a learner
- **GIVEN** a user whose only learniq group is `confidential-counsellors`
- **WHEN** the primary role is resolved
- **THEN** it is `confidential-counsellor`

### Requirement: Confidential notes are readable by their author and named participants only
`ConfidentialNote` MUST declare an `authorization` block whose read entries are exactly: a `confidential-counsellors` member matched on `authorId`, and any authenticated user contained in `participantIds`. Create MUST be `confidential-counsellors`; update and delete MUST be a `confidential-counsellors` member matched on `authorId`. No action MAY name `instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`, `learners` or `guardians`.

#### Scenario: A school leader cannot read a note
- **GIVEN** a `ConfidentialNote` written by vertrouwenspersoon `vp-01` with no participants
- **WHEN** a user in `administration-managers` and `compliance-officers` lists confidential notes
- **THEN** the note is not returned

#### Scenario: A named participant reads the note but cannot change it
- **GIVEN** a note by `vp-01` with `participantIds: ["staff-07"]`
- **WHEN** `staff-07` reads it and then tries to update it
- **THEN** the read succeeds and the update is refused

#### Scenario: A former vertrouwenspersoon loses access
- **GIVEN** a note by `vp-01`, and `vp-01` has been removed from `confidential-counsellors`
- **WHEN** `vp-01` lists confidential notes
- **THEN** the note is not returned

### Requirement: Confidential notes are structurally isolated
`ConfidentialNote` MUST NOT carry a `$ref` to another schema, no other schema MAY `$ref` it, it MUST NOT be searchable, it MUST hard-delete, and it MUST NOT be `appendOnly`.

#### Scenario: A pupil record never leads to a confidential note
- **GIVEN** the register
- **WHEN** every schema's properties are scanned for references
- **THEN** none references `ConfidentialNote` and `ConfidentialNote` references none

### Requirement: The confidential notes menu is shown to confidential counsellors only
The manifest MUST expose a "Confidential notes" menu entry with index and detail pages for `confidential-note`, gated on `user.isConfidentialCounsellor`, which the page shell fills from `DashboardRoleService::isConfidentialCounsellor()`.

#### Scenario: A mentor does not see the menu
- **GIVEN** a user in `instructors` only
- **WHEN** the app navigation renders
- **THEN** there is no "Confidential notes" entry

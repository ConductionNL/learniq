# BPV

## ADDED Requirements

### Requirement: BpvPlacement access is enforced, with the school coach and the learner as scopes
`BpvPlacement` MUST declare an `authorization` block. Read MUST be granted to `instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators` and `administration-managers`, to the user whose id is in `schoolCoachId`, and to the user whose id is in `learnerId`. Create and update MUST be granted to `instructors`, `hr`, `compliance-officers`, `team-leads` and `coordinators`. The block MUST NOT grant delete.

#### Scenario: A coordinator-only stagecoördinator sees the placements
- **GIVEN** a user in the `coordinators` group and in no other learniq group
- **WHEN** they open the BPV placements index
- **THEN** the placements of their tenant are listed

#### Scenario: The named coach reads their own placement
- **GIVEN** a `BpvPlacement` whose `schoolCoachId` is user `coach-01`, who is in none of the listed groups
- **WHEN** `coach-01` reads that placement
- **THEN** the read succeeds, and a placement naming another coach stays hidden from them

#### Scenario: A learner reads their own placement only
- **GIVEN** two placements, one with `learnerId` `leerling-01` and one for another learner
- **WHEN** `leerling-01` lists placements
- **THEN** only their own placement is returned

### Requirement: Praktijkopleider access is enforced for the BPV staff groups
`Praktijkopleider` MUST declare an `authorization` block. Read MUST be granted to `instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators` and `administration-managers`; create and update to `instructors`, `hr`, `compliance-officers`, `team-leads` and `coordinators`. It MUST NOT carry a self-match entry, because a praktijkopleider has no Nextcloud account.

#### Scenario: A coordinator registers a workplace supervisor
- **GIVEN** a user in the `coordinators` group
- **WHEN** they create a `Praktijkopleider` for a leerbedrijf
- **THEN** the object is created and they can read it back

#### Scenario: A learner cannot read supervisor contact details
- **GIVEN** a user in no staff group
- **WHEN** they list `Praktijkopleider` objects
- **THEN** no object is returned

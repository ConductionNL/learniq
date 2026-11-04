# action-authorization Specification (delta)

## Purpose

Who may run which learniq action: a matrix of actions and Nextcloud groups that every guarded endpoint asks, and that an administrator edits without a developer. Covers rows `gov-decide-who-may-do-what` and `gov-change-permissions-yourself` (ADR-023).

## ADDED Requirements

### Requirement: Every guarded action is decided by the action matrix

learniq SHALL keep one action matrix per instance that maps each action id (for example `compliance.department-rollup` or `audit-pack.export`) to the Nextcloud groups allowed to run it. A new instance SHALL start from the defaults in `lib/actions.seed.json`. An endpoint guarded by an action SHALL ask the matrix before it reads or writes anything, and SHALL answer 403 when the caller is in none of the action's groups. Members of the Nextcloud admin group SHALL always pass.

#### Scenario: An allowed group runs the action

- **GIVEN** `compliance.department-rollup` allows the group `hr`
- **WHEN** a member of `hr` requests the department roll-up
- **THEN** the roll-up is returned

#### Scenario: A user outside the action's groups is refused

- **GIVEN** `audit-pack.export` allows no group besides the administrators, as seeded
- **WHEN** a teacher requests an audit pack
- **THEN** the server answers 403 and no pack is built

### Requirement: An administrator changes the matrix without a developer

Admin settings, Learniq SHALL show the matrix with one row per action, one column per Nextcloud group and a checkbox in each cell. Saving SHALL store the whole matrix through `PUT /api/admin/action-matrix`, and the next request SHALL follow it without a restart or a deploy. Only administrators SHALL read or change the matrix. A save that leaves out a seeded action, or gives an action something other than a list of group ids, SHALL be refused with a 400, and the stored matrix SHALL stay as it was.

#### Scenario: An administrator lets team leads run an action

- **GIVEN** `regulation.assign` allows `compliance-officers` and `hr`, as seeded
- **WHEN** an administrator ticks `team-leads` for it and saves
- **THEN** a team lead can assign the rule's courses on the next request

#### Scenario: A malformed save keeps the old matrix

- **GIVEN** a stored matrix
- **WHEN** a save arrives without one of the seeded actions
- **THEN** the server answers 400 and the stored matrix is unchanged

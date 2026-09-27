# school-structure

## ADDED Requirements

### Requirement: Cohort activation provisions and maintains a real Nextcloud group

When a Cohort transitions `planned` → `active`, the system SHALL provision a
real Nextcloud group (`OCP\IGroupManager::createGroup()`), add every resolvable
user in `teacherIds` and `learnerIds` as a member, and write the provisioned
group's id back onto `Cohort.ncGroupId`. Provisioning SHALL be idempotent: a
Cohort whose `ncGroupId` is already set SHALL NOT be provisioned again. After
activation, an `Enrolment` transitioning `activate` or `withdraw` SHALL add or
remove that Enrolment's learner from its Cohort's Nextcloud group, when that
Cohort has already been provisioned (`ncGroupId` set); when the Cohort has not
yet been provisioned, the sync SHALL no-op rather than error. A user id that
does not resolve to a real Nextcloud user SHALL be skipped, never block the
provisioning or the sync.

#### Scenario: Activating a Cohort provisions its Nextcloud group

- **GIVEN** a `Cohort` with `teacherIds: ["teacher-1"]`, `learnerIds: ["learner-1", "learner-2"]`, and `ncGroupId: null`
- **WHEN** the Cohort's `activate` transition runs
- **THEN** a Nextcloud group is created, `teacher-1`, `learner-1`, and `learner-2` are added as members, and the Cohort's `ncGroupId` is saved as the provisioned group's id

#### Scenario: Activating an already-provisioned Cohort does not provision a second group

- **GIVEN** a `Cohort` whose `ncGroupId` is already set
- **WHEN** an `activate`-shaped event is handled for that Cohort again
- **THEN** no new group is created and no group-membership call is made

#### Scenario: Enrolling a learner into an active Cohort adds them to its group

- **GIVEN** a `Cohort` with a provisioned `ncGroupId`
- **WHEN** an `Enrolment` referencing that Cohort transitions `activate`
- **THEN** the Enrolment's `learnerId` is added as a member of the Cohort's Nextcloud group

#### Scenario: Withdrawing an enrolment removes the learner from the group

- **GIVEN** a `Cohort` with a provisioned `ncGroupId` and a learner currently a member of it via an active `Enrolment`
- **WHEN** that `Enrolment` transitions `withdraw`
- **THEN** the learner is removed as a member of the Cohort's Nextcloud group

#### Scenario: An Enrolment change on a not-yet-provisioned Cohort is a no-op, not an error

- **GIVEN** a `Cohort` whose `ncGroupId` is still null
- **WHEN** an `Enrolment` referencing that Cohort transitions `activate` or `withdraw`
- **THEN** no group-membership call is made and no exception is raised

#### Scenario: An unresolvable user id is skipped, not fatal

- **GIVEN** a `Cohort` with a `teacherIds` or `learnerIds` entry that does not resolve to a real Nextcloud user
- **WHEN** the Cohort's `activate` transition runs
- **THEN** the unresolvable id is skipped and every other resolvable id is still added as a group member

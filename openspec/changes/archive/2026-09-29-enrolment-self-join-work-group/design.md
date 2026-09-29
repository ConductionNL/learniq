# Design: enrolment-self-join-work-group

## Context

A course group in learniq is a `Cohort` (`lib/Settings/learniq_register.json:5526`). Group hand-in exists as a flag (`Assignment.groupSubmission`) with a multi-member `Submission.learnerIds`, but `SubmitWorkView.submit()` writes only the current user (`src/views/SubmitWorkView.vue:122-131`). There is no object for a work group inside a cohort. `GroupPlanSubgroup` (`:14034`) is the PO group plan's instructional level and stays as it is.

## Data model

### `WorkGroup` (new, slug `work-group`, 0.1.0)

| property | type | notes |
|---|---|---|
| `cohortId` | uuid, `$ref: Cohort`, required | |
| `courseId` | uuid, `$ref: Course`, nullable | when the groups belong to one course of the cohort |
| `setName` | string, required | groups made together form a set, for example "Project marketing periode 2"; a learner is in at most one group per set |
| `name` | string, required | "Groep 3" |
| `maxMembers` | integer, minimum 1, required | |
| `memberIds` | array of strings | Nextcloud user ids |
| `memberCount` | integer | declared calculation: length of `memberIds` |
| `selfJoinUntil` | date-time, nullable | null means teachers place learners, no self sign-up |
| `lifecycle` | `open`, `closed` | `close` and `reopen` by staff |
| `tenant_id` | string, required | |

Authorization:

- read, create, update: `instructors`, `team-leads`, `compliance-officers`. Learners neither read nor write a `WorkGroup` through the object API.
- Learners read through `GET /api/my/work-groups`, which returns the sets of the cohorts whose `learnerIds` hold the caller. `Cohort`'s own member rule matches on `ncGroupId` (`lib/Settings/learniq_register.json:5526` onwards), which stays null until the open change `cohort-group-provisioning` provisions groups, so a register `match` on it would show learners nothing today; the endpoint does not depend on it.

### `Assignment` (0.4.1 to 0.5.0)

- `workGroupSetName` (string, nullable): with `groupSubmission` true, the hand-in fills `learnerIds` from the learner's group in this set of the assignment's cohort.

## Endpoints

`GET /api/my/work-groups`, `POST /api/work-groups/{id}/join` and `POST /api/work-groups/{id}/leave`, `#[NoAdminRequired]` with the checks in the body (gate 7):

- my work groups: the sets and groups of every cohort whose `learnerIds` hold the caller, with names, free places and the member names of each group.

- join: the group is `open`; now is before `selfJoinUntil`; the caller is in the cohort's `learnerIds`; `memberIds` has fewer than `maxMembers` after a fresh read; the caller is not a member of another group with the same `cohortId` and `setName`. A member of another group in the set is moved: removed there, added here, in one service call.
- leave: the group is `open`, before `selfJoinUntil`, caller is a member.

Writes go through `ObjectService` with `_rbac: false` after the checks, as the check-in and allocation services do. The service serialises joins per group with `ILockingProvider` on a key `learniq-work-group-<id>`.

## Screens

- `CohortDetail` gets a "Work groups" tab (a custom widget `WorkGroupsWidget`) for staff: sets with their groups, members and free places; "Add groups" (count and size), move a member by drag or select, close sign-up.
- Learners: a page `/my-work-groups` (`MyWorkGroups.vue`) under My learning, listing sets from the learner's cohorts with each group's free places and Join, Move or Leave. After `selfJoinUntil` the buttons are gone and the page says sign-up has closed.
- `SubmitWorkView.submit()`: when the assignment has `groupSubmission` and `workGroupSetName`, it reads the caller's group in that set and posts all `memberIds` as `learnerIds`. `SubmissionWindowGuard`'s rule that only a listed learner may submit is unchanged.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `WorkGroup` lifecycle | declarative | a two-state machine |
| `memberCount` | declarative calculation | a derived count |
| join, move, leave with a size cap | imperative, `WorkGroupMembershipService` | ADR-031 exception: a write the caller's object rights must not allow, with a check across rows (one group per set) and a lock against a race on the last place |

## Seed data

MBO example set: cohort "MV2A marketing en communicatie", set "Project campagne periode 2" with five `WorkGroup` rows of four places ("Groep 1" to "Groep 5"), three full, one with two members, one empty, `selfJoinUntil` a week after the period starts; the assignment "Campagneplan" with `groupSubmission: true` and `workGroupSetName` set.

## Open points

- Whether learners of a cohort may see the names of the members of other groups; the default shows names, since that is what a class sees on the board. A school that wants counts only is a later setting.

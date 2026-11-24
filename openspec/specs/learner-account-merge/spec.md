---
status: done
---

# learner-account-merge Specification

## Purpose

Merge two LearnerProfiles that belong to the same person, so the results of both accounts end up on one. The merged profile is kept as an audit link and its Nextcloud user is not touched. This spec describes the code on development as of 7 October 2026 (learniq#950): the `merge` transition on LearnerProfile in `lib/Settings/learniq_register.json`, `lib/Lifecycle/LearnerMergeGuard.php`, `lib/Listener/LearnerMergeHandler.php` and `lib/Service/LearnerMergeService.php`. It covers capability row `gov-merge-duplicate-accounts`.

No page offers the `merge` transition yet: `LearnerProfileDetail` in `src/manifest.d/people.json` declares no `lifecycleActions`, so the transition is reached through OpenRegister's lifecycle API only.

## Requirements

### Requirement: A merge MUST name another active profile and MUST NOT leave two open seats in one course

The LearnerProfile lifecycle SHALL offer a `merge` transition from `active` to `merged`, guarded by `LearnerMergeGuard`. The guard MUST refuse the merge, with the reason in the refusal, when `mergedInto` is empty, names the profile itself, names a profile that does not exist, names a profile that is not `active`, or when both accounts hold an enrolment in state `pending` or `active` in the same course (`LearnerMergeService::refusalReason()`).

#### Scenario: A merge into an active profile with no shared open course is allowed
<!-- @e2e exclude No page offers the merge transition; the guard is covered by PHPUnit tests/Unit/Listener/LearnerMergeHandlerTest.php (testGuard*). -->

- **GIVEN** an active LearnerProfile A whose `mergedInto` names active LearnerProfile B
- **AND** A and B hold no open enrolment in the same course
- **WHEN** the `merge` transition is applied to A
- **THEN** the guard allows it and A moves to `merged`

#### Scenario: A merge into itself is refused
<!-- @e2e exclude No page offers the merge transition; the guard is covered by PHPUnit tests/Unit/Listener/LearnerMergeHandlerTest.php (testGuard*). -->

- **GIVEN** an active LearnerProfile A whose `mergedInto` names A
- **WHEN** the `merge` transition is applied
- **THEN** the guard refuses it with "a profile cannot be merged into itself"
- **AND** A stays `active`

#### Scenario: Two open seats in one course block the merge
<!-- @e2e exclude No page offers the merge transition; the guard is covered by PHPUnit tests/Unit/Listener/LearnerMergeHandlerTest.php (testGuard*). -->

- **GIVEN** profiles A and B that both hold an `active` enrolment in course C
- **WHEN** the `merge` transition is applied to A with `mergedInto` B
- **THEN** the guard refuses it and names course C

### Requirement: A completed merge MUST move every learner-owned record to the surviving profile

When a LearnerProfile transitions to `merged`, `LearnerMergeHandler` SHALL call `LearnerMergeService::moveRecords()`, which MUST rewrite every reference to the merged learner on the learner-owned schemas listed in `LearnerMergeService::LEARNER_OWNED` (enrolments, grades, attendance, credentials, portfolio entries and the others carrying `learnerId` or `learnerRef`, plus FraudCase `accusedLearnerId`). Each reference MUST be matched against both the merged learner's Nextcloud user id and profile UUID and rewritten to the survivor's counterpart. Each moved record MUST be saved through OpenRegister, so its audit trail records the move.

#### Scenario: Grades and enrolments follow the survivor
<!-- @e2e exclude Covered by PHPUnit tests/Unit/Listener/LearnerMergeHandlerTest.php; no page offers the merge transition. -->

- **GIVEN** profile A with user id `a.smit` holds two grade entries and one enrolment
- **WHEN** A is merged into profile B with user id `asmit2`
- **THEN** the two grade entries and the enrolment name `asmit2` and B's UUID
- **AND** each moved record has an audit trail entry for the change

#### Scenario: A credential stored with the user id in the profile field is still moved
<!-- @e2e exclude No page offers the merge transition; the dual match is LearnerMergeService::rewriteMap(), read from code. -->

- **GIVEN** a Credential whose `learnerId` holds A's Nextcloud user id instead of A's profile UUID
- **WHEN** A is merged into B
- **THEN** the credential's `learnerId` is rewritten to B's counterpart

### Requirement: The merged profile MUST stay as the audit link

The merge SHALL NOT rewrite or delete either LearnerProfile, and SHALL NOT change the merged learner's Nextcloud user. `mergedInto` MUST stay set on the merged profile.

#### Scenario: The merged profile still points at the survivor
<!-- @e2e exclude Covered by PHPUnit tests/Unit/Listener/LearnerMergeHandlerTest.php; no page offers the merge transition. -->

- **GIVEN** A was merged into B
- **WHEN** an administrator opens A
- **THEN** A is in state `merged` and `mergedInto` names B
- **AND** A's Nextcloud user still exists

### Requirement: An administrator merges a duplicate account from the learner's page

`LearnerProfileDetail` MUST offer "Merge into another account" to users in `hr` and `compliance-officers` on a profile in state `active`, and to nobody else. The action MUST ask for the surviving profile through a picker limited to other active profiles, MUST ask for confirmation naming both profiles, and MUST then run the `merge` transition with `mergedInto` set to the chosen profile. The page MUST NOT offer `delete`.

#### Scenario: Merge two accounts of one pupil

- **GIVEN** two active profiles of one pupil with no open enrolment in the same course, and a user in `hr`
- **WHEN** the user opens the duplicate, chooses "Merge into another account", picks the other profile and confirms
- **THEN** the duplicate is `merged` with `mergedInto` set to the chosen profile
- **AND** the duplicate's enrolments belong to the chosen profile

#### Scenario: A teacher does not see the action

- **GIVEN** a user in `instructors` only
- **WHEN** the user opens an active profile
- **THEN** the header offers no merge

#### Scenario: The picker leaves out the profile itself and inactive ones

- **GIVEN** an active profile A, an active profile B and a merged profile C
- **WHEN** a user in `hr` starts the merge on A
- **THEN** the picker offers B and offers neither A nor C

### Requirement: A refused merge says why and changes nothing

When `LearnerMergeGuard` refuses, the page MUST show the guard's reason and both profiles MUST stay as they were.

#### Scenario: Two open seats in one course

- **GIVEN** two active profiles that both hold an open enrolment in one course
- **WHEN** a user in `hr` merges one into the other
- **THEN** the page shows the refusal reason naming the course
- **AND** both profiles stay `active` with their own enrolments

### Requirement: A merged profile points to the surviving one

A profile in state `merged` MUST show "Merged into" with a link to the surviving profile, and MUST offer no lifecycle action.

#### Scenario: Open an old account

- **GIVEN** a profile merged into another
- **WHEN** a user in `hr` opens it
- **THEN** the page shows "Merged into" with the survivor's name as a link

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

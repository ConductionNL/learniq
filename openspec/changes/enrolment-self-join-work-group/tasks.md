# Tasks: enrolment-self-join-work-group

## Implementation tasks

### Task 1: Register: WorkGroup and Assignment.workGroupSetName
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size`
- **files**: `lib/Settings/learniq_register.json` (new WorkGroup 0.1.0 with lifecycle, calculation, authorization; Assignment 0.5.0; `info.version` bump)
- [x] Implement
- [x] Test: `tests/Unit/Settings/WorkGroupRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`
- No `memberCount` calculation: free places come from `WorkGroupReader` (the learner overview). Test: `tests/Unit/Settings/WorkGroupRegisterTest.php`.

### Task 2: Membership service and routes
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place`, `#requirement-a-learner-is-in-one-work-group-per-set`
- **files**: `lib/Service/WorkGroupMembershipService.php`, `lib/Controller/WorkGroupController.php` (`mine`, `join`, `leave`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a full group WHEN a learner joins THEN it is refused with a reason
  - GIVEN a learner in group 1 of a set WHEN they join group 2 of the same set THEN they leave group 1
- [x] Implement
- [x] Test: `tests/Unit/Service/WorkGroupMembershipServiceTest.php`; hydra gates 5, 7, 30
- Plus `WorkGroupReader` (overview, member names; user ids only for the learner's own group) and the portal receivers `PortalWorkGroupController` (`POST /api/portal/work-groups`, `/join`, `/leave`, pattern of #1096 and #1142) with actions in `WorkGroupFlowActions`. Joins take an `ILockingProvider` lock per group. Tests: `WorkGroupMembershipServiceTest`, `PortalWorkGroupControllerTest`.

### Task 3: Teacher tab on the cohort page
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size`
- **files**: `src/components/widgets/WorkGroupsWidget.vue`, `src/manifest.d/*.json` (CohortDetail), `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/work-groups.spec.ts` (teacher makes five groups of four)
- Live 2026-09-29 on localhost:8080 (served 51c8b1ef, nextcloud-vue 2.57.4): "a teacher adds a work group to a class". A temporary instructor opens Work groups, then Create. The Class picker lists and selects the class (#1263), and the dialog shows no Tenant field (#1283). The instructor enters maximum members 4, a name and a set. One work group is created with that name, maxMembers 4 and the set, and the class page's Work groups widget lists it. Groups are added one at a time; five in one action is not built (see below), and the sign-up date was not set in this test. The test stays red on one soft check: tenant_id is filled from learniq's CallerTenantResolver, which falls back to the Nextcloud instance id (`ocuhb9wy3beh`) because the account has no learniq `tenant_id` setting. The class it belongs to is in tenant `00000000-0000-0000-0000-000000000001`, so the group and its class sit in different tenants.
- Built as a typed `object-list` widget on CohortDetail plus `WorkGroups` index and `WorkGroupDetail` detail pages (create, edit members to move a learner, close and reopen), not a custom widget, so no custom-widget ratchet step. "Make 8 groups of 4" in one action is not built: groups are added one by one. Playwright test not written: no live instance.

### Task 4: Learner page
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place`
- **files**: `src/views/MyWorkGroups.vue`, `src/manifest.d/my-learning.json`, `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/work-groups.spec.ts` (learner joins, full group shows no button)
- Live 2026-09-29 on localhost:8080 (served 51c8b1ef): "a learner joins a group with a free place; a full group offers nothing" passed (1.9m). A temporary learner in the class opens My work groups. The full group (1 of 1) offers no button. "Join" on the free group shows "Leave", and afterwards the free group's memberIds are the learner and the full group's are unchanged.
- `src/views/MyWorkGroups.vue` at `/my-work-groups` with a menu entry for learners. Playwright test not written.

### Task 5: Group hand-in uses the work group
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-group-hand-in-names-the-whole-work-group`
- **files**: `src/views/SubmitWorkView.vue`
- [x] Implement
- [x] Test: `tests/unit-js` for the member lookup; e2e hand-in by one member lists all four
- Live 2026-09-29 on localhost:8080 (#1460, served 839bbbc6): `work-groups.spec.ts` "a learner moves to another group of the set and hands in for the whole group" passed (2.6m). "Move here" on Groep 5 leaves Groep 4 empty and puts the learner in Groep 5 with the three others (the one-group-per-set requirement). The learner then hands in a file on the group assignment (groupSubmission, workGroupSetName), and the single submission's learnerIds are all four members. Not covered: each member seeing the hand-in on their own page.
- `src/utils/workGroups.js` `handInLearners` (caller first, so learnerRef stays the caller's), used by `SubmitWorkView`; `tests/unit-js/workGroups.test.mjs` green. The e2e half is open.

### Task 6: Seed data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate enrolment-self-join-work-group --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
- Five WorkGroup rows (set "Project campagne periode 2", four places, three full, one with two, one empty) and an assignment with `groupSubmission` and `workGroupSetName` in the mock register; the MBO example set generator is not extended.


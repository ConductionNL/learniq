# Tasks: enrolment-self-join-work-group

## Implementation tasks

### Task 1: Register: WorkGroup and Assignment.workGroupSetName
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size`
- **files**: `lib/Settings/learniq_register.json` (new WorkGroup 0.1.0 with lifecycle, calculation, authorization; Assignment 0.5.0; `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/WorkGroupRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`

### Task 2: Membership service and routes
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place`, `#requirement-a-learner-is-in-one-work-group-per-set`
- **files**: `lib/Service/WorkGroupMembershipService.php`, `lib/Controller/WorkGroupController.php` (`mine`, `join`, `leave`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a full group WHEN a learner joins THEN it is refused with a reason
  - GIVEN a learner in group 1 of a set WHEN they join group 2 of the same set THEN they leave group 1
- [ ] Implement
- [ ] Test: `tests/Unit/Service/WorkGroupMembershipServiceTest.php`; hydra gates 5, 7, 30

### Task 3: Teacher tab on the cohort page
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size`
- **files**: `src/components/widgets/WorkGroupsWidget.vue`, `src/manifest.d/*.json` (CohortDetail), `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/work-groups.spec.ts` (teacher makes five groups of four)

### Task 4: Learner page
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place`
- **files**: `src/views/MyWorkGroups.vue`, `src/manifest.d/my-learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/work-groups.spec.ts` (learner joins, full group shows no button)

### Task 5: Group hand-in uses the work group
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-group-hand-in-names-the-whole-work-group`
- **files**: `src/views/SubmitWorkView.vue`
- [ ] Implement
- [ ] Test: `tests/unit-js` for the member lookup; e2e hand-in by one member lists all four

### Task 6: Seed data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate enrolment-self-join-work-group --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

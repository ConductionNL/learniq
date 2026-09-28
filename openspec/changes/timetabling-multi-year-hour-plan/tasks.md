# Tasks: timetabling-multi-year-hour-plan

## Implementation tasks

### Task 1: Register: HourPlan and Cohort.programmeYear
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `lib/Settings/learniq_register.json` (new HourPlan 0.1.0 with lifecycle, calculations, authorization; Cohort 0.2.0; `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/HourPlanRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`

### Task 2: Activation guard
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `lib/Lifecycle/HourPlanActivationGuard.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Lifecycle/HourPlanActivationGuardTest.php` (second active plan for the same intake refused)

### Task 3: Activity service, route and event
- **spec_ref**: `specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs`
- **files**: `lib/Service/HourPlanActivityService.php`, `lib/Controller/HourPlanController.php`, `lib/Event/HourPlanActivitiesQueryEvent.php`, a listener, `appinfo/routes.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Service/HourPlanActivityServiceTest.php` (programme year to intake year arithmetic, teacher lookup); hydra gates 5, 7, 30

### Task 4: Plan editor and programme widget
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `src/views/HourPlanEditor.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/hour-plan.spec.ts` (enter hours, see a shortfall, copy to next intake)

### Task 5: Activities page
- **spec_ref**: `specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs`
- **files**: `src/views/HourPlanActivities.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/hour-plan.spec.ts` (activities for 2026-2027 list MV2A)

### Task 6: Rollover raises programmeYear
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in`
- **files**: the rollover wizard's cohort step (change school-year-rollover)
- [ ] Implement
- [ ] Test: unit test on the rollover step

### Task 7: Seed data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-multi-year-hour-plan --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

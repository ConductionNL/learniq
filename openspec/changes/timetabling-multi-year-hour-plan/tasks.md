# Tasks: timetabling-multi-year-hour-plan

## Implementation tasks

### Task 1: Register: HourPlan and Cohort.programmeYear
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `lib/Settings/learniq_register.json` (new HourPlan 0.1.0 with lifecycle, calculations, authorization; Cohort 0.2.0; `info.version` bump)
- [x] Implement (HourPlan 0.1.0, Cohort 0.2.0 with `programmeYear`, info.version 0.32.0. The totals and shortfalls are not `x-openregister-calculations`: the calculation grammar has no sum over an array, so `src/utils/hourPlan.js` `yearTotals()` computes them in the editor)
- [x] Test: `tests/Unit/Settings/HourPlanRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`

### Task 2: Activation guard
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `lib/Lifecycle/HourPlanActivationGuard.php`
- [x] Implement
- [x] Test: `tests/Unit/Lifecycle/HourPlanActivationGuardTest.php` (second active plan for the same intake refused)

### Task 3: Activity service, route and event
- **spec_ref**: `specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs`
- **files**: `lib/Service/HourPlanActivityService.php`, `lib/Controller/HourPlanController.php`, `lib/Event/HourPlanActivitiesQueryEvent.php`, a listener, `appinfo/routes.php`
- [x] Implement (listener `lib/Listener/HourPlanActivitiesQueryListener.php`, wired by `lib/AppInfo/Registrar/QueryListenerRegistrar.php`)
- [x] Test: `tests/Unit/Controller/HourPlanControllerTest.php` as well; `tests/Unit/Service/HourPlanActivityServiceTest.php` (programme year to intake year arithmetic, teacher lookup); hydra gates 5, 7, 30

### Task 4: Plan editor and programme widget
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length`
- **files**: `src/views/HourPlanEditor.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [x] Implement (ProgrammeDetail widget "Hour plans"; the editor's "Copy to next intake" creates the draft)
- [x] Test: `tests/unit-js/hourPlan.test.mjs` for the grid, totals and copy; Playwright `tests/e2e/hour-plan.spec.ts` (enter hours, see a shortfall; written, not run in this lane)

### Task 5: Activities page
- **spec_ref**: `specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs`
- **files**: `src/views/HourPlanActivities.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [x] Implement (route `/teaching-activities`, menu Timetabling)
- [x] Test: Playwright `tests/e2e/hour-plan.spec.ts` (activities for 2026-2027 list MV2A; written, not run in this lane)

### Task 6: Rollover raises programmeYear
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in`
- **files**: the rollover wizard's cohort step (change school-year-rollover)
- [x] Implement (`RolloverExecutionService::nextProgrammeYear()`; a group that stays in its programme keeps it)
- [x] Test: unit test on the rollover step (`RolloverServiceTest::testExecuteRaisesTheProgrammeYear`)

### Task 7: Seed data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement (the MBO set's own programmes: an active plan per programme and intake year with a class in 2025-2026, a draft for the next Software developer intake, `programmeYear` on every class)
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-multi-year-hour-plan --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

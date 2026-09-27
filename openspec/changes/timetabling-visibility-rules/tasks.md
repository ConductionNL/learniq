# Tasks: timetabling-visibility-rules

## Implementation tasks

### Task 1: Move learner-facing session reads to endpoints
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-the-api-follows-the-same-line`
- **files**: every read of `session` in `src/` and `lib/` found by `git grep -n "'session'"` (at least `src/views/CohortTimetableView.vue:60`)
- [ ] Implement
- [ ] Test: the list of reads with their new path in the PR body; existing e2e for MyTimetable and the cohort timetable stay green

### Task 2: Register: policy and Session authorization
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see`, `#requirement-the-api-follows-the-same-line`
- **files**: `lib/Settings/learniq_register.json` (new policy schema, `Session` 0.2.0 authorization, `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/TimetableVisibilityRegisterTest.php`; the register ratchet tests stay at their current set

### Task 3: Endpoint and options
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows`
- **files**: `lib/Controller/TimetableController.php` (`of`, `ofOptions`), `lib/Service/TimetableVisibilityService.php`, `appinfo/routes.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Service/TimetableVisibilityServiceTest.php` (each policy value for each role); hydra gates 5, 7, 30

### Task 4: Timetables page, week component, settings section
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows`
- **files**: `src/views/Timetables.vue`, `src/components/TimetableWeek.vue`, `src/views/MyTimetable.vue`, `src/views/CohortTimetableView.vue`, `src/manifest.d/learning.json`, `src/registry.js`, the settings page
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/timetable-visibility.spec.ts` (learner opens a related teacher, cannot open another group)

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-visibility-rules --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

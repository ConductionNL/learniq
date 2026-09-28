# Tasks: timetabling-enrolment-forecast

## Implementation tasks

### Task 1: Register: EnrolmentForecast
- **spec_ref**: `specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject`
- **files**: `lib/Settings/learniq_register.json` (new schema, `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/EnrolmentForecastRegisterTest.php`; `npm run check:register`

### Task 2: Forecast service and route
- **spec_ref**: `specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject`, `#requirement-the-forecast-says-how-many-groups-are-needed`
- **files**: `lib/Service/EnrolmentForecastService.php`, `lib/Controller/EnrolmentForecastController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN rates of 0.9, 0.05 and 0.05 for 100 learners WHEN computed THEN 90 move up, 5 repeat, 5 leave
  - GIVEN rates that do not add up to 1 WHEN computed THEN it is refused with a reason
- [ ] Implement
- [ ] Test: `tests/Unit/Service/EnrolmentForecastServiceTest.php`; hydra gates 5, 7, 30

### Task 3: Forecast page and Reports card
- **spec_ref**: `specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject`
- **files**: `src/views/EnrolmentForecastView.vue`, `src/manifest.json` (Reports card), `src/manifest.d/progress.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/enrolment-forecast.spec.ts` (change a rate, see the result change, export)

### Task 4: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-enrolment-forecast --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

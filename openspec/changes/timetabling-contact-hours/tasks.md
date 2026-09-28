# Tasks: timetabling-contact-hours

## Implementation tasks

### Task 1: Contact hours service
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`
- **files**: `lib/Service/ContactHoursService.php`
- **acceptance_criteria**:
  - GIVEN three cancelled lessons WHEN the report runs THEN they are not counted as given
  - GIVEN a cohort without an hour plan WHEN the report runs THEN owed shows as missing, not as zero
- [ ] Implement
- [ ] Test: `tests/Unit/Service/ContactHoursServiceTest.php` (proration by period and by teaching weeks, holidays excluded, cancelled excluded, attended from lesuren)

### Task 2: Route
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`
- **files**: `lib/Controller/ContactHoursController.php`, `appinfo/routes.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/ContactHoursControllerTest.php` (learner refused); hydra gates 5, 7, 30

### Task 3: Report page and Reports card
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`, `#requirement-a-learner-who-attended-too-little-is-marked`
- **files**: `src/views/ContactHoursReport.vue`, `src/manifest.json` (Reports card), `src/manifest.d/learning.json` (page), `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/contact-hours.spec.ts` (MV2A shows a shortfall in Engels; drill-down lists a learner at 70 percent)

### Task 4: Example data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-contact-hours --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

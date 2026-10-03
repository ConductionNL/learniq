# Tasks: timetabling-contact-hours

## Implementation tasks

### Task 1: Contact hours service
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`
- **files**: `lib/Service/ContactHoursService.php`, `lib/Service/ContactHoursReader.php`, `lib/Timetabling/ContactHoursCalendar.php`
- **acceptance_criteria**:
  - GIVEN three cancelled lessons WHEN the report runs THEN they are not counted as given
  - GIVEN a cohort without an hour plan WHEN the report runs THEN owed shows as missing, not as zero
- [x] Implement
- [x] Test: `tests/Unit/Service/ContactHoursServiceTest.php` (proration by period, cancelled excluded, attended from lesuren, no plan means missing) and `tests/Unit/Timetabling/ContactHoursCalendarTest.php` (proration by teaching days, holidays excluded)

### Task 2: Route
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`
- **files**: `lib/Controller/ContactHoursController.php`, `appinfo/routes.php`, `lib/actions.seed.json` (`report.contact-hours`)
- [x] Implement
- [x] Test: `tests/Unit/Controller/ContactHoursControllerTest.php` (learner refused); hydra gates 5, 7, 30

### Task 3: Report page and Reports card
- **spec_ref**: `specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours`, `#requirement-a-learner-who-attended-too-little-is-marked`
- **files**: `src/views/ContactHoursReport.vue`, `src/manifest.json` (Reports card), `src/manifest.d/learning.json` (page), `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/spec-coverage/timetabling-contact-hours.spec.ts` (report renders, learner drill-down opens); written, not run: no instance in this lane. The shortfall and the 70 percent learner are asserted in `ContactHoursServiceTest`.

### Task 4: Example data and translations
- **files**: MBO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement: translations (`l10n/nl.json`, `l10n/en.json`, rebuilt `l10n/*.js`). Example data: not changed. The MBO example set of `timetabling-multi-year-hour-plan` (PR #1312, which this change is stacked on) already carries every class's sessions and an active hour plan per programme and intake, so the report has owed against given there; editing `scripts/example-sets/mbo.py` for the MV2A story would conflict with that open PR, and belongs in a follow-up once it lands.
- [x] Test: gate 101 (no schema change, so no demo rows needed), `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-contact-hours --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

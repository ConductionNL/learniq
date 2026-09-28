# Tasks: timetabling-room-utilisation

## Implementation tasks

### Task 1: Opening hours setting
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`
- **files**: `lib/Controller/SettingsController.php`, the settings page
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/SettingsControllerTest.php` (only staff groups write it)

### Task 2: Utilisation service and route
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`, `#requirement-lessons-without-a-room-are-counted-not-hidden`
- **files**: `lib/Service/RoomUtilisationService.php`, `lib/Controller/RoomUtilisationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a holiday week WHEN the report covers it THEN its days add no open hours
  - GIVEN a cancelled lesson WHEN the report runs THEN it adds no hours in use
- [ ] Implement
- [ ] Test: `tests/Unit/Service/RoomUtilisationServiceTest.php`; hydra gates 5, 7, 30

### Task 3: Report page and Reports card
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`
- **files**: `src/views/RoomUtilisationReport.vue`, `src/manifest.json` (Reports card), `src/manifest.d/learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/room-use.spec.ts` (gyms above 90 percent, labs below 40 percent, export)

### Task 4: Example data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-room-utilisation --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

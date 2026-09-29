# Tasks: timetabling-room-utilisation

## Implementation tasks

### Task 1: Opening hours setting
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`
- **files**: `lib/Controller/SettingsController.php`, the settings page
- [x] Implement (in `lib/Service/OpeningHoursSettings.php` and `RoomUtilisationController::openingHours()` / `saveOpeningHours()` at `/api/reports/room-use/opening-hours`, edited on the report page itself: the learniq admin settings page is admin only, and team leads must be able to change it)
- [x] Test: `tests/Unit/Controller/RoomUtilisationControllerTest.php::testOpeningHoursWriters` (only team leads and compliance officers write it)

### Task 2: Utilisation service and route
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`, `#requirement-lessons-without-a-room-are-counted-not-hidden`
- **files**: `lib/Service/RoomUtilisationService.php`, `lib/Controller/RoomUtilisationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a holiday week WHEN the report covers it THEN its days add no open hours
  - GIVEN a cancelled lesson WHEN the report runs THEN it adds no hours in use
- [x] Implement (with `lib/Service/RoomUseTally.php` for the running totals; lessons from the current timetable source, a planninq `roomReference` matched to `Room.code`)
- [x] Test: `tests/Unit/Service/RoomUtilisationServiceTest.php`; hydra gates 5, 7, 30

### Task 3: Report page and Reports card
- **spec_ref**: `specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used`
- **files**: `src/views/RoomUtilisationReport.vue`, `src/manifest.json` (Reports card), `src/manifest.d/learning.json`, `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/room-use.spec.ts` (gym and lab hours, a roomless lesson; written, not run in this lane); `tests/unit-js/roomUse.test.mjs` for the percentages and the export

### Task 4: Example data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement (translations. No new example rows: the VO set's lessons are one whole-day block per class in its own classroom, so the report already shows busy classrooms and empty gyms and labs; extra gym lessons for a class would overlap its day block and be flagged as a double booking by SessionConflictListener)
- [x] Test: gate 101 not applicable (no schema change), `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-room-utilisation --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

# Tasks: timetabling-display-screens

## Implementation tasks

### Task 1: Register: DisplayScreen
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen`
- **files**: `lib/Settings/learniq_register.json` (new schema with lifecycle, authorization and a property read rule hiding `tokenHash`; `info.version` bump)
- [x] Implement
- [x] Test: `tests/Unit/Settings/DisplayScreenRegisterTest.php`

### Task 2: Token issue, renew, revoke
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen`
- **files**: `lib/Service/DisplayScreenService.php`, `lib/Controller/DisplayScreenController.php`, `appinfo/routes.php`, `lib/actions.seed.json` (`display-screen.manage`)
- [x] Implement
- [x] Test: `tests/Unit/Service/DisplayScreenServiceTest.php` (hash stored, token returned once, revoked token refused), `tests/Unit/Controller/DisplayScreenControllerTest.php`; hydra gates 5, 7, 30

### Task 3: Public route and projection
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user`, `#requirement-a-display-screen-never-shows-personal-data`
- **files**: `lib/Controller/DisplayScreenPublicController.php`, `lib/Service/DisplayScreenBoard.php`, `lib/Timetabling/Source/PlanninqTimetableSource.php` (carries `subject` and `teacherReference`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a cancelled lesson with a free-text reason WHEN projected THEN only `change: cancelled` is in the answer
- [x] Implement
- [x] Test: `tests/Unit/Controller/DisplayScreenPublicControllerTest.php` (unknown token 404, output keys pinned, no reason, learner or user id in the answer); a security-change test per gate 104

### Task 4: Screen page and admin page
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user`
- **files**: `src/views/DisplayScreenView.vue`, `src/display.js` (own webpack entry), `templates/display.php`, `src/views/DisplayScreenAddressView.vue`, `src/manifest.d/learning.json` (DisplayScreens index, detail and address page), `src/registry.js`, `src/icons.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/spec-coverage/timetabling-display-screens.spec.ts` (create screen, open address in a signed-out context); written, not run: no instance in this lane

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-display-screens --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

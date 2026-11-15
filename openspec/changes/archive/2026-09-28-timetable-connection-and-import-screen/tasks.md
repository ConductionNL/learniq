# Tasks: timetable-connection-and-import-screen

## Implementation Tasks

### Task 1: Report the timetable connection from planninq (must, V1)
- **spec_ref**: `openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-the-timetable-connection-is-available-when-planninq-and-integriq-are-installed`
- **files**: `lib/Settings/connections.json`, `lib/Service/ConnectionReportService.php`, `lib/BackgroundJob/ConnectionReportJob.php`, `lib/Controller/SettingsController.php`, `tests/Unit/Service/ConnectionReportServiceTest.php`, `tests/Unit/Settings/ConnectionsDeclarationTest.php`
- [x] Implement
- [x] Test

### Task 2: The import button and its access check (must, V1)
- **spec_ref**: `openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-the-timetable-page-offers-the-import-to-whoever-may-request-an-exchange`
- **files**: `lib/Controller/TimetableImportController.php`, `appinfo/routes.php`, `src/dialogs/TimetableImportDialog.vue`, `src/views/TimetableConflictQueue.vue`, `tests/Unit/Controller/TimetableImportControllerTest.php`
- [x] Implement
- [x] Test

### Task 3: The admin settings section (must, V1)
- **spec_ref**: `openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page`
- **files**: `lib/Service/TimetableExchangeSettings.php`, `lib/Controller/TimetableExchangeSettingsController.php`, `src/views/settings/TimetableExchangeSettingsSection.vue`, `src/views/settings/AdminRoot.vue`, `src/utils/timetableExchangeSettings.js`, `l10n/*`, `docs/installation.md`, tests
- [x] Implement
- [x] Test

### Task 4: The kept map feeds the import (must, V1)
- **spec_ref**: `openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-import-without-a-posted-map-uses-the-kept-map`
- **files**: `lib/Timetabling/PlanninqTimetableImport.php`, `tests/Unit/Timetabling/PlanninqTimetableImportTest.php`
- [x] Implement
- [x] Test

## Verification
- [x] PHPUnit for the touched classes; node test for the settings helpers
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates

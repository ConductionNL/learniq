# Tasks: lesson-sharing-consent-gate

## Implementation Tasks

### Task 1: Gate and package builder
- **spec_ref**: `openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate`, `#requirement-a-share-package-carries-no-school-bound-or-personal-fields`
- **files**: `lib/Service/CourseSharingGate.php`, `lib/Service/CourseSharePackageBuilder.php`, `tests/Unit/Service/CourseSharingGateTest.php`, `tests/Unit/Service/CourseSharePackageBuilderTest.php`
- **acceptance_criteria**:
  - GIVEN each refusal case in the spec WHEN checked THEN the matching blocker code is returned, and an open course with both confirmations returns none
  - GIVEN a payload WHEN stripped THEN none of the listed keys remain and LTI placements are empty; the sharing block holds no user id
- [x] Implement
- [x] Test

### Task 2: Share export service, consent schema and record
- **spec_ref**: `openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-every-share-export-leaves-a-consent-record`
- **files**: `lib/Service/CourseShareExportService.php`, `lib/Exception/SharingBlockedException.php`, `lib/Service/CoursePackageExportService.php`, `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `tests/Unit/Service/CourseShareExportServiceTest.php`, `tests/Unit/Settings/CourseShareConsentRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN a blocked course WHEN exported for sharing THEN `SharingBlockedException` carries the blockers and no consent is written
  - GIVEN an open course WHEN exported THEN one consent is written with the spec's fields and the stripped package is returned
  - GIVEN `CourseShareConsent` WHEN read THEN its authorization matches the spec
- [x] Implement
- [x] Test

### Task 3: Controller, route and action
- **spec_ref**: `openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate`
- **files**: `lib/Controller/CourseSharingController.php`, `appinfo/routes.php`, `lib/actions.seed.json`, `tests/Unit/Controller/CourseSharingControllerTest.php`
- **acceptance_criteria**:
  - GIVEN no session WHEN called THEN 401; GIVEN a blocked course THEN 422 with blockers; GIVEN an open course THEN a JSON download
  - GIVEN the call WHEN made THEN `requireAction(..., 'course-package.share')` runs first
- [x] Implement
- [x] Test

### Task 4: Export page share switch
- **spec_ref**: `openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations`
- **files**: `src/views/ExportRequestView.vue`, `src/utils/customPages.js`, `l10n/en.json`, `l10n/nl.json`, `docs/user-guide/user/02-create-course.md`
- **acceptance_criteria**:
  - GIVEN the switch WHEN on THEN both confirmations show and submit posts to the share route
  - GIVEN a 422 WHEN received THEN each blocker shows as a translated sentence
- [x] Implement
- [x] Test

## Quality checklist
- PHPUnit for gate, builder, service, controller and schema.
- Browser check of the export page when the shared Playwright service is back (it was down during this lane); the Vue change is covered by eslint, prettier and code review only.
- Dutch and English strings for every new label.
- `openspec validate lesson-sharing-consent-gate` passes.

# Tasks: lesson-sharing-via-store-plane

## Implementation Tasks

### Task 1: Store plane descriptor, registry object and SharedCoursePackage schema
- **spec_ref**: `openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane`, `#requirement-any-learniq-instance-can-act-as-the-registry`
- **files**: `lib/Service/CourseStore/CourseStoreDescriptor.php`, `lib/Service/CourseStore/CourseStoreRegistryObject.php`, `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `tests/Stubs/AppHost/Service/*.php`, `psalm.xml`, tests
- **acceptance_criteria**:
  - GIVEN the descriptor WHEN built THEN it names `learniq`, `shared-course-package`, register `learniq` and the card fields of the spec
  - GIVEN a share package WHEN turned into a registry object THEN every card field is a string, the slug matches the pattern, and the package travels whole
- [x] Implement
- [x] Test

### Task 2: Install as a copy that keeps the credit
- **spec_ref**: `openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit`
- **files**: `lib/Service/CourseStore/CourseStoreInstaller.php`, `lib/Service/CoursePackage/CoursePackageObjectWriter.php`, `lib/Service/CoursePackage/LearniqJsonCourseImporter.php`, tests
- **acceptance_criteria**:
  - GIVEN a resolved item WHEN installed THEN the package is imported through `CoursePackageImportService` and the temp file is removed
  - GIVEN a package course with licence and author WHEN imported THEN the new course carries them; invalid values are dropped
- [x] Implement
- [x] Test

### Task 3: Publisher
- **spec_ref**: `openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry`
- **files**: `lib/Service/CourseStore/CourseStorePublisher.php`, `lib/Service/CourseStore/CourseStoreUrlGuard.php`, tests
- **acceptance_criteria**:
  - GIVEN no registry, an unsafe URL, a non-2xx answer or an oversized package WHEN publishing THEN the matching outcome, and no request where the spec says none
  - GIVEN a configured registry WHEN publishing THEN one POST to the objects URL with the Bearer header, redirects off, 10 second timeouts
- [x] Implement
- [x] Test

### Task 4: StoreController and routes
- **spec_ref**: all requirements above
- **files**: `lib/Controller/StoreController.php`, `appinfo/routes.php`, `tests/Unit/Controller/StoreControllerTest.php`
- **acceptance_criteria**:
  - GIVEN search, install and publish WHEN called THEN each answers as the API design says, with the session and action checks first
- [x] Implement
- [x] Test

### Task 5: Store page, publish button, docs
- **spec_ref**: `openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane`
- **files**: `src/manifest.json`, `src/views/ExportRequestView.vue`, `src/utils/customPages.js`, `l10n/`, `docs/user-guide/user/02-create-course.md`, `docs/user-guide/admin/03-admin-settings.md`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN validated THEN `check:manifest` and `check:menu-role-gates` pass
  - GIVEN the export page in share mode WHEN "Publish to the course store" is used THEN the outcome shows as a translated sentence
- [x] Implement
- [x] Test

## Quality checklist
- PHPUnit for every new class; the controller test calls each action.
- Browser check of the Store page and the publish button when the shared Playwright service is back.
- Dutch and English strings for every new label.
- `openspec validate lesson-sharing-via-store-plane` passes.

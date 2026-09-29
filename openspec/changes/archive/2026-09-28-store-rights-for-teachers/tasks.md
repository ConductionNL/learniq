# Tasks: store-rights-for-teachers

Feature tier: should (sharing, D27). Stacked on store-publish-through-plane.

## Implementation Tasks

### Task 1: Matrix rows and the install action
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-any-teacher-installs-a-shared-course-as-a-copy`
- **files**: `lib/actions.seed.json`, `lib/Controller/StoreController.php`, `tests/Unit/Controller/StoreControllerTest.php`, `tests/Unit/Settings/StoreRightsSeedTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the seed WHEN read THEN course-store.install is ["admin","instructors","team-leads"], course-package.share is ["admin","team-leads"], course-package.import is ["admin"]
  - GIVEN the seed groups WHEN compared with the Course, Lesson and Material create grants THEN every seed group except admin may create all three
  - GIVEN an install WHEN the controller checks THEN it asks course-store.install
- [x] Implement
- [x] Test

### Task 2: Once-only repair step for existing installs
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once`
- **files**: `lib/Repair/ApplyStoreRightsDefaults.php`, `appinfo/info.xml`, `tests/Unit/Repair/ApplyStoreRightsDefaultsTest.php`
- **acceptance_criteria**:
  - GIVEN an untouched matrix WHEN it runs THEN both rows get the new defaults and the marker is set
  - GIVEN the marker WHEN it runs THEN nothing changes
  - GIVEN a customised share row WHEN it runs THEN that row stays
- [x] Implement
- [x] Test

### Task 3: Store access for the signed-in user
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take`
- **files**: `lib/Service/CourseStore/StoreAccessService.php`, `lib/Controller/PageController.php`, `tests/Unit/Service/CourseStore/StoreAccessServiceTest.php`, `tests/Unit/Controller/PageControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a teacher WHEN forUser THEN {install: true, publish: false}
  - GIVEN a team lead on an OpenRegister that cannot publish WHEN forUser THEN publish is false
  - GIVEN a page load WHEN index() THEN initial state storeAccess is provided
- [x] Implement
- [x] Test

### Task 4: The page, the publish button and the menu follow the answer
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take`
- **files**: `src/utils/storeAccess.js`, `src/main.js`, `src/manifest.json`, `src/manifest.d/learning.json`, `src/views/ExportRequestView.vue`, `tests/unit-js/storeAccess.test.mjs`
- **acceptance_criteria**:
  - GIVEN storeAccess WHEN applyStoreAccess runs THEN every store page config carries canInstall and canPublish, other pages are untouched
  - GIVEN the manifest WHEN read THEN the Store page names publishRoute CoursePackageExport and the export menu admits team-lead
  - GIVEN storeAccess.publish false WHEN the export screen renders THEN no publish button
- [x] Implement
- [x] Test

### Task 5: Registry connection in the admin settings
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings`
- **files**: `lib/Controller/StoreRegistrySettingsController.php`, `appinfo/routes.php`, `src/views/settings/StoreRegistrySettingsSection.vue`, `src/views/settings/AdminRoot.vue`, `tests/Unit/Controller/StoreRegistrySettingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a stored token WHEN GET THEN tokenSet true and no token in the body
  - GIVEN a PUT without token WHEN saved THEN the token is kept; with clearToken THEN removed; with token THEN stored sensitive
  - GIVEN ftp:// or user info in the URL, or a malformed register WHEN PUT THEN 400 and nothing stored
- [x] Implement
- [x] Test

### Task 6: Copy, catalogue and docs
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings`
- **files**: `l10n/nl.json`, `l10n/nl.js`, `docs/user-guide/admin/03-admin-settings.md`, `docs/user-guide/user/02-create-course.md`
- **acceptance_criteria**:
  - GIVEN every new string WHEN the catalogue is read THEN it has a Dutch value
  - GIVEN the admin guide WHEN read THEN it describes the settings section, the two matrix rows and the release dependency
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for every new or changed class; the full suite once in `check:strict`.
- `node --test tests/unit-js/storeAccess.test.mjs` for the boot helper.
- No new register schema, so no seed rows; `npm run check:schema-l10n` still passes.
- Browser screenshots (ADR-010): not taken if the shared browser service is unavailable; say so in the PR.
- `openspec validate store-rights-for-teachers` passes.

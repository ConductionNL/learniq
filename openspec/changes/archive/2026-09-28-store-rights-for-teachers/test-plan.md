# Test Plan: store-rights-for-teachers

## Test Cases

### TC-1: Seed rows and install action
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-any-teacher-installs-a-shared-course-as-a-copy`
- **type**: security
- **preconditions**: `lib/actions.seed.json`, `lib/Settings/learniq_register.json`
- **steps**: read the seed; compare its install groups with the create grants of Course, Lesson and Material; install through `StoreController`
- **expected result**: the seed values of the proposal; every non-admin install group may create all three; the controller asks `course-store.install`
- **test command**: `vendor/bin/phpunit --filter 'StoreRightsSeedTest|StoreControllerTest'`

### TC-2: The repair step runs once and respects choices
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once`
- **type**: regression
- **preconditions**: matrices: untouched, customised share row, marker set, empty
- **steps**: run the step
- **expected result**: untouched gets both defaults and the marker; customised keeps its row; marker set changes nothing; empty is left alone without the marker
- **test command**: `vendor/bin/phpunit --filter ApplyStoreRightsDefaultsTest`

### TC-3: Store access per user
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take`
- **type**: functional
- **persona**: a teacher (instructors), a team lead (team-leads)
- **steps**: `StoreAccessService::forUser()`; `PageController::index()`
- **expected result**: teacher `{install: true, publish: false}`; team lead publish true only when OpenRegister can publish and the plane admits; `storeAccess` provided as initial state
- **test command**: `vendor/bin/phpunit --filter 'StoreAccessServiceTest|PageControllerTest'`

### TC-4: Boot helper and manifest
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take`
- **type**: functional
- **steps**: `applyStoreAccess()` on a manifest with a store page and another page; read `src/manifest.json` and `src/manifest.d/learning.json`
- **expected result**: only store pages gain `canInstall`/`canPublish`; missing state means false; Store names `publishRoute: "CoursePackageExport"`; the export menu admits `team-lead`
- **test command**: `node --test tests/unit-js/storeAccess.test.mjs`

### TC-5: Registry settings endpoints
- **spec_ref**: `openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings`
- **type**: security
- **steps**: GET with a stored token; PUT without token, with token, with clearToken; PUT `ftp://…`, a URL with user info, a register with capitals
- **expected result**: no token in any response; kept, stored sensitive, removed; 400 and nothing stored for the malformed inputs; methods carry `AuthorizedAdminSetting`
- **test command**: `vendor/bin/phpunit --filter StoreRegistrySettingsControllerTest`

## Coverage Summary
- Any teacher installs a shared course as a copy: TC-1.
- Publishing to the store defaults to team leads: TC-1 (seed), TC-3.
- Existing installs get the new store defaults once: TC-2.
- The Store page shows each user the actions they may take: TC-3, TC-4.
- An administrator connects the course registry in the admin settings: TC-5.

## Out of Scope
The rendered Install and Publish buttons: they come from nextcloud-vue #1268, whose own
jest suite covers them, and learniq's lockfile does not carry that release yet.

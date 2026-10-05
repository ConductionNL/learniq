# Tasks: simple-structure-profile

## Implementation tasks

### Task 1: The profile mechanism
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-001-two-structures-are-built-from-one-manifest`
- **files**: `src/utils/structureProfile.js`, `src/main.js`
- [x] Implement
- [x] Test

### Task 2: The simple menu, per role
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-002-the-simple-menu-shows-at-most-ten-entries-per-role-under-three-captions`
- **files**: `src/menu-layout.simple.json`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test

### Task 3: Links for what left the menu
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-003-what-leaves-the-menu-is-linked-or-named`
- **files**: `src/menu-layout.simple.json`
- [x] Implement
- [x] Test

### Task 4: The setting
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default`
- **files**: `lib/Service/Settings/MenuStructure.php`, `lib/Service/SettingsService.php`, `lib/Controller/PageController.php`, `src/views/settings/MenuStructureSection.vue`, `src/services/menuStructureSetting.js`, `src/views/settings/AdminRoot.vue`
- [x] Implement
- [x] Test

### Task 5: Tests in the scripts CI runs
- **files**: `tests/unit-js/structureProfile.test.mjs`, `package.json`, `tests/Unit/Service/Settings/MenuStructureTest.php`, `tests/e2e/simple-structure-menu.spec.ts`, `tests/e2e/ci-seed.sh`
- [x] Implement
- [x] Test (the e2e spec is written, not run: no throwaway instance in this lane)

## Not in this change

- [ ] Hub pages for planning, admissions and my learning (waits for the typed link-cards page)
- [ ] A messages page (the design's Berichten)
- [ ] A view of only my groups

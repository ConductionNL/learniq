# Tasks: simple-today-dashboard

## Implementation tasks

### Task 1: An overlay may be for some roles and may change a page's kind
- **spec_ref**: `openspec/changes/simple-today-dashboard/specs/dashboard/spec.md#requirement-req-std-001-the-start-page-is-today-for-the-teaching-roles-in-the-simple-structure`
- **files**: `src/utils/structureProfile.js`, `src/main.js`
- [x] Implement
- [x] Test

### Task 2: The Today dashboard
- **spec_ref**: `openspec/changes/simple-today-dashboard/specs/dashboard/spec.md#requirement-req-std-002-every-number-on-today-opens-the-list-it-counts`
- **files**: `src/menu-layout.simple.json`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test

### Task 3: Tests in the scripts CI runs
- **files**: `tests/unit-js/structureProfile.test.mjs`, `tests/e2e/simple-structure-menu.spec.ts`
- [x] Implement
- [x] Test (the e2e scenario is written, not run: no throwaway instance in this lane)

## Not in this change

- [ ] A list of everything that waits for marking, and its count on Today
- [ ] Signals per pupil, by name
- [ ] A Today for learners and guardians

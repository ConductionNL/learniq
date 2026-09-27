# Tasks: staff-role-vocabulary-extension

## Implementation Tasks

### Task 1: Extend Staff.roles with seven function tags and labels
- **spec_ref**: `openspec/changes/staff-role-vocabulary-extension/specs/school-structure/spec.md#requirement-staff-roles-name-the-counsellor-and-exam-functions-a-school-staffs`, `#requirement-every-staff-role-has-a-readable-translated-label`, `#requirement-a-staff-role-tag-grants-no-access`
- **files**: `lib/Settings/learniq_register.json` (`Staff.properties.roles`, `Staff.version`, `info.version`, `info.description` changelog sentence, one `x-openregister-seed` row), `lib/Settings/learniq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN `Staff.roles.items.enum` WHEN read THEN the original seven values are its first seven entries, in order, followed by the seven new ones
  - GIVEN `Staff.roles.items['x-enum-labels']` WHEN read THEN it has a non-empty label for every enum value and no extra keys
  - GIVEN the `roles` description WHEN read THEN it states that a tag grants no access
  - GIVEN the seed and demo rows WHEN validated against the enum THEN every role value is allowed
- [x] Implement
- [x] Test

### Task 2: Catalogue keys for the labels
- **spec_ref**: `openspec/changes/staff-role-vocabulary-extension/specs/school-structure/spec.md#requirement-every-staff-role-has-a-readable-translated-label`
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- **acceptance_criteria**:
  - GIVEN every label WHEN looked up THEN `l10n/en.json` has an identity key and `l10n/nl.json` a Dutch value
  - GIVEN `npm run check:schema-l10n` WHEN run THEN the uncovered count does not grow
- [x] Implement
- [x] Test

### Task 3: Register-shape tests
- **spec_ref**: all requirements in `specs/school-structure/spec.md` above
- **files**: `tests/Unit/Settings/StaffRoleVocabularyRegisterTest.php` (new), `tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the new test WHEN run THEN it asserts the enum floor and order, the label map, the no-access description, that no `authorization` block names a tag-only value, and that seed rows use allowed values
  - GIVEN the existing Staff test WHEN run THEN it asserts the original seven values as a floor, not the exact list
- [x] Implement
- [x] Test

## Quality checklist
- No new PHP business logic; register-shape tests cover the change.
- No API endpoint change, so no Newman update.
- No page change; the roles picker renders through the existing Staff form.
- Dutch and English strings added for every new label.
- `openspec validate staff-role-vocabulary-extension` passes.

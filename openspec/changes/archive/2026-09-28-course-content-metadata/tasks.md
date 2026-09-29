# Tasks: course-content-metadata

## Implementation Tasks

### Task 1: Add the four fields to Course and Lesson
- **spec_ref**: `openspec/changes/course-content-metadata/specs/course-management/spec.md#requirement-courses-and-lessons-carry-sharing-metadata-aligned-to-nl-lom`, `#requirement-licence-and-level-values-have-translated-labels`
- **files**: `lib/Settings/learniq_register.json` (`Course` and `Lesson` properties, both `version`s, `info.version`, changelog sentence), `lib/Settings/learniq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN `Course` and `Lesson` WHEN read THEN both declare `license`, `author`, `subject`, `educationalLevels`, none required, `license` without default
  - GIVEN `license` and `educationalLevels.items` WHEN read THEN each value has an `x-enum-labels` entry
- [x] Implement
- [x] Test

### Task 2: Catalogue keys
- **spec_ref**: `openspec/changes/course-content-metadata/specs/course-management/spec.md#requirement-licence-and-level-values-have-translated-labels`
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- **acceptance_criteria**:
  - GIVEN every new title, description and label WHEN looked up THEN it has an English key and a Dutch value
  - GIVEN `npm run check:schema-l10n` WHEN run THEN the uncovered count does not grow
- [x] Implement
- [x] Test

### Task 3: Register test
- **spec_ref**: all requirements above
- **files**: `tests/Unit/Settings/CourseContentMetadataRegisterTest.php` (new), `tests/Unit/Settings/CourseAuthoringRegisterTest.php` (its exact Course and Lesson version pin becomes a floor)
- **acceptance_criteria**:
  - GIVEN the test WHEN run THEN it pins the fields, enums, labels and catalogue values on both schemas and the version bumps
- [x] Implement
- [x] Test

## Quality checklist
- No PHP logic; register test covers the shape.
- No endpoint or page change.
- Dutch and English strings for every new label.
- `openspec validate course-content-metadata` passes.

# Tasks: competency-year-scope

## Implementation Tasks

### Task 1: Add applicableYears and subjectId to Competency
- **spec_ref**: `openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-a-competency-declares-the-years-it-is-taught-in`, `#requirement-a-competency-declares-the-subject-it-belongs-to`, `#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor`
- **files**: `lib/Settings/learniq_register.json` (`Competency.properties.applicableYears`, `Competency.properties.subjectId`, `Competency.version` 0.1.0 to 0.2.0, `info.version` minor bump)
- **tier**: must (MVP), round 2 plan wave 1 lane L2
- **acceptance_criteria**:
  - GIVEN `Competency` WHEN read THEN `applicableYears` is an array of strings, default `[]`, `uniqueItems: true`, items `minLength: 1` and `maxLength: 64`, no enum
  - GIVEN `Competency` WHEN read THEN `subjectId` is a nullable `format: uuid` string with `$ref: Course`, default `null`
  - GIVEN `Competency.required` WHEN read THEN it is unchanged (`frameworkId`, `code`, `title`, `tenant_id`)
  - GIVEN the two descriptions WHEN read THEN they state the label scheme and the inherit-from-ancestor read rule
- [x] Implement
- [x] Test

### Task 2: Demo data and translation keys
- **spec_ref**: `openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-a-competency-declares-the-years-it-is-taught-in`
- **files**: `lib/Settings/learniq_mock_register.json` (three `Competency` demo rows per design.md Seed Data, demo `info.version` bump), `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `l10n/.schema-l10n-baseline.json` if the count drops
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the gate-101 checker WHEN run on the changed descriptors THEN every schema's demo data validates
  - GIVEN `npm run check:schema-l10n` WHEN run THEN it exits 0 (the uncovered count does not grow)
  - GIVEN `l10n/nl.json` WHEN read THEN each new title and description has a Dutch value
- [x] Implement
- [x] Test

### Task 3: Register unit test
- **spec_ref**: all requirements in `specs/competency/spec.md`
- **files**: `tests/Unit/Settings/CompetencyYearScopeRegisterTest.php` (new)
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the suite WHEN run THEN it asserts both property shapes, the unchanged `required` list, the version bump, and that the demo rows carry the fields
- [x] Implement
- [x] Test

## Verification
- `openspec validate competency-year-scope --strict` passes
- `vendor/bin/phpunit --filter CompetencyYearScopeRegisterTest` passes
- `npm run check:register`, `npm run check:json-strict`, `npm run check:schema-l10n`, `npm run check:l10n-js` pass
- `composer check:strict` once before push

## Quality checklist
- PHPUnit covers the new register shape (ADR-009). No new business logic, so no coverage delta beyond the test.
- Newman: N/A, no new or changed endpoint.
- Playwright: N/A, no new page; the existing Competency pages render the properties through the data widget.
- Documentation (ADR-010): N/A for this change; the user-facing curriculum coverage page and its docs land in `curriculum-coverage-matrix-view`.
- i18n (ADR-005): English and Dutch catalogue values for every new schema string (Task 2).

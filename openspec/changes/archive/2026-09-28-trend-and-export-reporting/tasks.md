# Tasks: trend-and-export-reporting

## Implementation Tasks

### Task 1: Add columns and actionToggles to the four indexes
- **spec_ref**: `openspec/changes/trend-and-export-reporting/specs/data-exchange/spec.md#requirement-pupil-cohort-report-card-and-attendance-indexes-declare-columns-and-explicit-built-in-mass-action-toggles`
- **files**: `src/manifest.d/people.json`, `src/manifest.d/learning.json`
- **acceptance_criteria**:
  - GIVEN the manifest is built WHEN `LearnerProfiles`/`Cohorts`/`ReportCards`/`AttendanceRecords` are inspected THEN each declares a non-empty `columns` array and all four `actionToggles.showMass*` flags `true`
- [x] Implement
- [x] Test

### Task 2: Add the ImportExportToolsMenu nav entry
- **spec_ref**: `openspec/changes/trend-and-export-reporting/specs/data-exchange/spec.md#requirement-an-import--export-nav-entry-makes-the-existing-data-exchange-tooling-discoverable`
- **files**: `src/manifest.d/data-exchange.json`
- **acceptance_criteria**:
  - GIVEN the manifest is built WHEN `ImportExportToolsMenu` is inspected THEN it routes to `DataExchangeJobs` and gate-68 duplicate-index-pages reports 0 findings
- [x] Implement
- [x] Test

### Task 3: Add dle/leerrendement to GradeScale.kind
- **spec_ref**: `openspec/changes/trend-and-export-reporting/specs/grading/spec.md#requirement-gradescale-declares-dle-and-leerrendement-as-scale-kinds-for-later-lvs-data`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Settings/GradeScaleDleLeerrendementRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN `GradeScale.kind`'s enum is inspected THEN it includes `dle` and `leerrendement` alongside the six existing values
- [x] Implement
- [x] Test

## Quality checklist

- New schema shape covered by a PHPUnit register test (`tests/Unit/Settings/`)
- No new API endpoints — every change is manifest config or a schema enum widening
- UI changes are declarative manifest additions only, no bespoke Vue component to browser-test
- All tests pass: `vendor/bin/phpunit --filter GradeScaleDleLeerrendementRegisterTest`
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new nav label and any new
  column display labels (ADR-007); no em-dashes, no Title Case
- `openspec validate --change trend-and-export-reporting` passes

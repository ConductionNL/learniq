# Migration: competency-year-scope

## Current State
`Competency` (register `learniq`, schema version 0.1.0) has no year or subject property.

## Target State
`Competency` (schema version 0.2.0) declares `applicableYears` (array of string, default `[]`) and `subjectId`
(nullable UUID, `$ref: Course`, default `null`). Nothing else changes.

## Migration Class
```
Version: none
File: none
Key operations:
- none: learniq stores no own tables (ADR-001). OpenRegister re-imports lib/Settings/learniq_register.json
  through the existing repair step when info.version changes.
```

## Migration Steps
1. Bump `Competency.version` to 0.2.0 and `info.version` so the repair step re-imports the register.
2. OpenRegister updates the stored schema definition. Existing objects are not rewritten.

## Data Impact
Every existing `Competency` object stays valid: both properties are optional, and a missing value reads as the
default. No data is transformed or lost. Safe on live data.

## Rollback Procedure
Revert the register diff and re-run the repair step. Objects that were saved with the two properties keep the
extra keys as unvalidated data until they are next edited; no reader outside this lane uses them yet.

## Validation
- `vendor/bin/phpunit --filter CompetencyYearScopeRegisterTest` passes.
- The hydra gate-101 checker (`generate_mock_register.py --check --only-changed`) reports every schema valid,
  including the three `Competency` demo rows.

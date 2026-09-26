# Migration: trend-and-export-reporting

## Current State
`GradeScale.kind` enum is `numeric`/`letter`/`ects`/`pass-fail`/`percentage`/`band`. No manifest
page in scope declares `columns` or `actionToggles`.

## Target State
`GradeScale.kind` enum additionally accepts `dle` and `leerrendement`. The four named index pages
declare `columns` and `actionToggles`.

## Migration Class
Not applicable — no Doctrine tables (this app owns none); the manifest changes need no migration at
all (read at request time), and the `GradeScale.kind` enum widening is handled by OpenRegister's own
schema-apply step on next app enable/upgrade.

## Migration Steps
1. Merge the register JSON patch (`GradeScale.kind` enum) and the manifest patches.
2. On next app enable/upgrade, OpenRegister reconciles the widened enum (no-op for every existing
   `GradeScale` row, since none uses the two new values yet).

## Data Impact
Zero rows change value. No data loss, no transformation. Safe on a live, populated instance.

## Rollback Procedure
Revert the register JSON patch and the manifest patches. No `GradeScale` row depends on the two new
enum values (none exist yet), so removing them is non-breaking.

## Validation
- `python3 -m json.tool lib/Settings/learniq_register.json` (well-formed JSON).
- `tests/Unit/Settings/GradeScaleDleLeerrendementRegisterTest.php` asserts the enum shape.
- `npm run check:specs` (manifest/menu-role-gates) for the manifest side.

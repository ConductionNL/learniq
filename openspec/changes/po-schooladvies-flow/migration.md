# Migration: po-schooladvies-flow

## Current State
No `SchoolAdvies` schema exists. `Application.schoolAdviceLevel`/`progressionTestLevel`/
`schoolAdviceAdjustedLevel` are VO-intake-scoped and unaffected by this change.

## Target State
A new `SchoolAdvies` schema exists with its own lifecycle, calculations, and the two new PHP
classes wired to it.

## Migration Class
Not applicable — no Doctrine tables (this app owns none). OpenRegister's own schema-apply step
provisions the new schema's object storage on next app enable/upgrade.

## Migration Steps
1. Merge the register JSON patch (new `SchoolAdvies` schema block).
2. Add `SchoolAdviesFinalizeGuard.php`/`SchoolAdviesSendToRodHandler.php`, register the listener in
   `lib/AppInfo/Application.php`.
3. On next app enable/upgrade, OpenRegister provisions the new schema's storage.
4. Seed data (`learniq_mock_register.json`) loads on fresh install only (existing `DemoDataService`
   convention).

## Data Impact
No existing schema/row is modified. Zero data loss, zero transformation. Safe on a live, populated
instance.

## Rollback Procedure
Revert the register JSON patch and remove the two new PHP classes plus their registration in
`Application.php`. Any `SchoolAdvies` objects already created become orphaned register data (same
rollback shape as any other schema removal in this register).

## Validation
- `python3 -m json.tool lib/Settings/learniq_register.json` (well-formed JSON).
- `php -l` on both new PHP files.
- `tests/Unit/Settings/SchoolAdviesRegisterTest.php`,
  `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php`,
  `tests/Unit/Listener/SchoolAdviesSendToRodHandlerTest.php` all green.

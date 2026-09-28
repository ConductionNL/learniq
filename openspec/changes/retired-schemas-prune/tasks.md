# Tasks: retired-schemas-prune

## Implementation Tasks

### Task 1: Evidence method on the payments archive
- **spec_ref**: `openspec/changes/retired-schemas-prune/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere`
- **files**: `lib/Repair/ArchiveRetiredPaymentObjects.php`, `tests/Unit/Repair/ArchiveRetiredPaymentObjectsTest.php`
- **acceptance_criteria**:
  - GIVEN rows all in the archive WHEN asked THEN true; a missing row, a count mismatch, no archive or a failed read THEN false
- [x] Implement
- [x] Test

### Task 2: PruneRetiredSchemas repair step
- **spec_ref**: `openspec/changes/retired-schemas-prune/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere`
- **files**: `lib/Repair/PruneRetiredSchemas.php`, `tests/Unit/Repair/PruneRetiredSchemasTest.php`, `tests/Stubs/Db/*`, `tests/Stubs/Service/SchemaDeletionService.php`, `psalm.xml`
- **acceptance_criteria**:
  - GIVEN an empty retired schema WHEN run THEN unlinked, then deleted
  - GIVEN unarchived payment rows, privacy requests or an archival schema WHEN run THEN left and reported
  - GIVEN nothing left WHEN run again THEN a no-op
- [x] Implement
- [x] Test

### Task 3: Wire the step after the archive, verify, PR
- **spec_ref**: `openspec/changes/retired-schemas-prune/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN info.xml WHEN read THEN the step is in post-migration after both reading steps and before InitializeSettings
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for every path of the step and both evidence methods
- No endpoint, no screen: no Newman, no Playwright
- Docs: not applicable (upgrade-time housekeeping, reported in the upgrade output)
- i18n: no new schema strings
- `openspec validate` passes

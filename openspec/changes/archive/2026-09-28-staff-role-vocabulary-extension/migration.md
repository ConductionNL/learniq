# Migration: staff-role-vocabulary-extension

## Current State
`Staff.roles.items.enum` holds seven values (`teacher`, `mentor`, `coordinator`, `teaching-assistant`, `support-staff`, `administrator`, `other`), no `x-enum-labels`. `Staff.version` is `0.1.0`.

## Target State
`Staff.roles.items.enum` holds fourteen values: the original seven in the same order, then `career-counsellor`, `study-adviser`, `remedial-teacher`, `care-coordinator`, `exam-secretary`, `placement-coordinator`, `confidential-counsellor`. `Staff.roles.items` declares `x-enum-labels` for all fourteen. `Staff.version` is `0.2.0`; `info.version` gets a patch bump.

## Migration Class
```
Version: none
File: none
Key operations:
- none. OpenRegister re-imports the register on app upgrade (repair step, ConfigurationService::importFromApp).
```
Learniq owns no database tables (ADR-001), so there is no Nextcloud migration class.

## Migration Steps
1. The app upgrade runs the existing register import repair step.
2. OpenRegister updates the `staff` schema definition to version `0.2.0`.

## Data Impact
None. The change widens an enum. Every stored row stays valid; no row is rewritten.

## Rollback Procedure
Revert the commit and upgrade again. Before that, map any row holding a new value to `other`, since the narrower enum would reject it on the next save.

## Validation
- `Staff` schema in OpenRegister reports version `0.2.0` and fourteen enum values.
- An existing `Staff` row reads and saves unchanged.
- `vendor/bin/phpunit --filter 'StaffRoleVocabularyRegisterTest|SubjectAndTeacherAssignmentRegisterTest'` passes.

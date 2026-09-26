## Implementation Tasks

### Task 1: Make `Cohort.authorization` explicit and add the own-group read entry
- **spec_ref**: `openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md#requirement-an-own-group-scope-kind-grants-read-access-via-a-callers-nextcloud-group-membership`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `Cohort.authorization` THEN `read`/`create`/`update` each contain exactly `["instructors","hr","compliance-officers","team-leads"]` as literal string entries
  - GIVEN `Cohort.authorization.read` THEN it additionally contains `{group: "authenticated", match: {ncGroupId: {"$in": "$user.groups"}}}`
  - GIVEN `Cohort.authorization` THEN no `delete` key is added (admin-only-by-omission preserved)
- [x] Implement
- [x] Test

### Task 2: Add `careTeamUserIds` and the care-team read entry to `DossierNote`
- **spec_ref**: `openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md#requirement-a-care-team-scope-kind-grants-read-access-via-an-array-of-user-ids-property`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `DossierNote.properties.careTeamUserIds` THEN it is `type: array`, `items.type: string`, `default: []`, with title+description
  - GIVEN `DossierNote.authorization.read` THEN the existing `["instructors","compliance-officers"]` entries are unchanged AND a new `{group: "authenticated", match: {careTeamUserIds: {"$contains": "$userId"}}}` entry is appended
  - GIVEN `DossierNote.authorization.create`/`update` THEN they are unchanged
- [x] Implement
- [x] Test

### Task 3: Register-shape tests for both scope-kind entries
- **spec_ref**: `openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md`
- **files**: `tests/Unit/Settings/RbacScopeKindsRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN it asserts `Cohort.authorization`'s exact grantee lists and the own-group entry's exact `{group, match}` keys
  - GIVEN the register THEN it asserts `DossierNote.careTeamUserIds`'s shape and the care-team entry's exact keys, and that the pre-existing two read entries are still present unchanged
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate rbac-scope-kinds-extension --strict` passes
- [x] Manual review against acceptance criteria

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for the register delta (`tests/Unit/Settings/RbacScopeKindsRegisterTest.php`)
- [x] N/A — no new API endpoint, no UI change; enforcement is OpenRegister core, verified by schema-shape assertions per this register's own established pattern (PupilDossierNotesRegisterTest, ExchangeRejectionRegisterTest, etc.)

## Documentation (company-wide ADR-010)
- [x] N/A — declarative RBAC config, no user-facing surface; the register's own schema descriptions are the documentation of record

## i18n (company-wide ADR-005)
- [x] N/A — no new user-facing strings

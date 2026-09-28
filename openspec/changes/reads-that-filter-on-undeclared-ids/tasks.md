# Tasks: reads-that-filter-on-undeclared-ids

## Implementation Tasks

### Task 1: A schema-independent scan refuses id and uuid filter keys
- **spec_ref**: `openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#requirement-no-read-filters-on-an-object-id-property`
- **files**: `tests/Unit/FindAllConfigScopeTest.php`
- **acceptance_criteria**:
  - on `development` the scan names 37 reads; controls prove each shape is caught and `ids` passes
- [x] Implement
- [x] Test

### Task 2: Every flagged read passes the id in ids
- **spec_ref**: `openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#scenario-a-read-by-id-puts-the-id-in-ids`
- **files**: 30 files under `lib/`
- **acceptance_criteria**:
  - the scan finds nothing; `php -l` clean; tenant and state filters unchanged
- [x] Implement
- [x] Test

### Task 3: The ratchet and the doubles follow
- **spec_ref**: `openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#scenario-the-known-list-shrinks-with-the-fix`
- **files**: `tests/Unit/Register/FindAllFilterKeysAreDeclaredTest.php`, nine doubles
- **acceptance_criteria**:
  - 29 entries leave `KNOWN_UNDECLARED`, six stay; corrected doubles fail on `development`'s `lib/` and pass here
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate reads-that-filter-on-undeclared-ids` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

## Quality checklist

- No new endpoint or string; no schema change

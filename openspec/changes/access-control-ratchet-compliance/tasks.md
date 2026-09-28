# Tasks: access-control-ratchet-compliance

Decision D23 (Ruben, 2026-09-27). Acceptance: the six access-control failures of development's twelve are green, and the full suite shows no new failure.

## Implementation Tasks

### Task 1: Authorization blocks on the import records
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Register/ImportRecordAccessTest.php`
- **acceptance_criteria**:
  - GIVEN the shipped register WHEN `LvsResult.authorization` is read THEN read is `coordinators`, `compliance-officers`, learner self; create and update are `coordinators`, `compliance-officers`; no delete
  - GIVEN the shipped register WHEN `OsoImportDossier.authorization` is read THEN read, create and update are `coordinators`, `compliance-officers`
- [x] Implement
- [x] Test

### Task 2: First-aid reporter self-read and no appendOnly on lifecycle schemas
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/pupil-dossier/spec.md#requirement-a-first-aid-incident-is-read-by-its-reporter-and-runs-its-lifecycle`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Register/LifecycleSchemasAreNotAppendOnlyTest.php`, `tests/Unit/Settings/LvsResultRegisterTest.php`, `src/manifest.d/pupil-record.json`
- **acceptance_criteria**:
  - GIVEN `FirstAidIncident` WHEN its read list is checked THEN it holds `{group: authenticated, match: {reportedBy: $userId}}`
  - GIVEN `LvsResult` and `FirstAidIncident` WHEN a transition runs THEN it lands, because neither is append-only
- [x] Implement
- [x] Test

### Task 3: Guards authorise coordinators
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them`
- **files**: `lib/Lifecycle/LvsResultVerifyGuard.php`, `lib/Lifecycle/OsoImportAcceptGuard.php`, `lib/Lifecycle/OsoImportRejectGuard.php` and their tests
- **acceptance_criteria**:
  - GIVEN a user in `coordinators` WHEN they verify, accept or reject THEN the guard allows it
  - GIVEN a user in `coordinator` WHEN they do the same THEN the guard refuses it
- [x] Implement
- [x] Test

### Task 4: DossierNote audience pins learn the care-team entry
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block`
- **files**: `tests/Unit/Register/DeclaredAudienceEnforcedTest.php`, `tests/Unit/Settings/RbacScopeKindsRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN `DossierNote.read` WHEN pinned THEN both conditional entries (author, care team) are expected, and the staff floor is unchanged
- [x] Implement
- [x] Test

### Task 5: Versions, verification, PR
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN shipped THEN each is at `0.2.0` and `info.version` is `0.29.0`
  - GIVEN the full suite WHEN run THEN the six failures of this change are gone and no new failure appears
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit covers every changed rule (`tests/Unit/`)
- No API endpoint changes, so no Newman collection
- No screen changes, so no Playwright run
- Documentation: not applicable, no user-facing behaviour described in `docs/` changes
- i18n: not applicable, no new user-facing strings
- `openspec validate` passes

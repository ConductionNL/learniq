# Test plan: access-control-ratchet-compliance

All cases are PHPUnit over the shipped register JSON and the guard classes. There is no screen: the rules are enforced by OpenRegister from the register.

### TC-1: The import records carry the reviewing groups' block
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them`
- **type**: security
- **preconditions**: the shipped register
- **steps**: read `LvsResult.authorization` and `OsoImportDossier.authorization`
- **expected result**: exactly the read, create and update lists of design.md; no delete key; no learner entry on `OsoImportDossier`
- **test command**: `vendor/bin/phpunit tests/Unit/Register/ImportRecordAccessTest.php`

### TC-2: No declared audience is left unenforced
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block`
- **type**: security
- **steps**: run the declared-audience ratchet
- **expected result**: no schema with `x-property-rbac` lacks a block; `LvsResult.learnerId` and `FirstAidIncident.reportedBy` are enforced; `DossierNote.read` is pinned with its author and care-team entries
- **test command**: `vendor/bin/phpunit tests/Unit/Register/DeclaredAudienceEnforcedTest.php tests/Unit/Settings/RbacScopeKindsRegisterTest.php`

### TC-3: The guards authorise the declared group only
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them`
- **type**: security
- **steps**: call each guard as a member of `coordinators`, of `coordinator`, of `instructors`, and with no user
- **expected result**: allowed for `coordinators` and `admin` only
- **test command**: `vendor/bin/phpunit tests/Unit/Lifecycle/ tests/Unit/Register/GuardGroupsAreDeclaredTest.php`

### TC-4: The lifecycles run
- **spec_ref**: `openspec/changes/access-control-ratchet-compliance/specs/pupil-dossier/spec.md#requirement-a-first-aid-incident-is-read-by-its-reporter-and-runs-its-lifecycle`
- **type**: regression
- **steps**: run `LvsResult.verify` from `imported` and `FirstAidIncident.startHandling` from `open` through the append-only ratchet
- **expected result**: `verified` and `in-handling`; no schema is append-only with transitions
- **test command**: `vendor/bin/phpunit tests/Unit/Register/LifecycleSchemasAreNotAppendOnlyTest.php tests/Unit/Settings/LvsResultRegisterTest.php`

## Coverage summary
- data-exchange requirement: TC-1, TC-3, TC-4 (covered)
- pupil-dossier requirement: TC-2, TC-4 (covered)
- nextcloud-app modified requirement: TC-1, TC-2 (covered)

## Out of scope
A live read as a pupil and as a coordinator on :8080 is not run: the instance belongs to no lane.

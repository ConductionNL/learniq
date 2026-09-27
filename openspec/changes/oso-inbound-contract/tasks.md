# Tasks: oso-inbound-contract

## 1. Schema: OsoImportDossier

- **spec_ref**: `openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#requirement-persist-osoimportdossier-for-inbound-overstapdossiers`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register schema catalogue
  - WHEN `OsoImportDossier` is added
  - THEN it declares `dataExchangeJobId`, `sourceSchoolBrin`, `learnerEckId`, `receivedAt`, `categories`
    (Besluit-style gegevensblok array), `draftProfile`, `attachmentRefs`, `rejectionReason`,
    `reviewedBy`/`reviewedAt`, `tenant_id`, with English `title`/`description` on every property
- [x] Implement
- [x] Test

## 2. Schema: reviewed-before-acceptance lifecycle gate + RBAC

- **spec_ref**: `openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#requirement-oso-import-is-reviewed-before-acceptance`
- **files**: `lib/Settings/learniq_register.json`, `lib/Lifecycle/OsoImportAcceptGuard.php`, `lib/Lifecycle/OsoImportRejectGuard.php`
- **acceptance_criteria**:
  - GIVEN an `OsoImportDossier` created by the import handler
  - WHEN it is created
  - THEN its lifecycle starts at `received` and only reaches `accepted`/`rejected` via guarded transitions
  - GIVEN a non admin/coordinator actor attempts `accept` or `reject`
  - WHEN the corresponding guard runs
  - THEN it denies the transition
  - GIVEN an admin/coordinator attempts `reject` with an empty `rejectionReason`
  - WHEN `OsoImportRejectGuard` runs
  - THEN the transition is refused
- [x] Implement
- [x] Test

## 3. Schema: `oso` inbound job target + DataMappingProfile seed

- **spec_ref**: `openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#requirement-oso-target-supports-the-import-direction`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN an `oso` (direction: import) profile is added
  - THEN its `sourceSchema` is `oso-import-dossier` and `fieldMappings` cover the incoming dossier's
    learner/school identity fields
- [x] Implement
- [x] Test

## 4. Register + guard tests

- **spec_ref**: `openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#requirement-persist-osoimportdossier-for-inbound-overstapdossiers`
- **files**: `tests/Unit/Settings/OsoImportDossierRegisterTest.php`, `tests/Unit/Lifecycle/OsoImportAcceptGuardTest.php`, `tests/Unit/Lifecycle/OsoImportRejectGuardTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON
  - WHEN it is parsed
  - THEN `OsoImportDossier`'s shape, lifecycle, and RBAC assertions hold, and both guards deny/allow per
    role and per `rejectionReason` presence
- [x] Implement
- [x] Test

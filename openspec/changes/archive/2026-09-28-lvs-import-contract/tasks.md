# Tasks: lvs-import-contract

## 1. Schema: LvsResult + import mapping profile

- **spec_ref**: `openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#requirement-persist-lvsresult-linked-to-assessmentresult`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `learniq_register.json` schema catalogue
  - WHEN `LvsResult` is added
  - THEN it declares `provider` (enum cito/iep/boom/dia), `instrument`, `moment`, `takenAt`, `rawScore`,
    `vaardigheidsscore`, `niveau`, `referentieniveau`, `dle`, `learnerId`, `assessmentResultId` (nullable
    `$ref` AssessmentResult), `dataExchangeJobId` (`$ref` DataExchangeJob), `tenant_id`, append-only, with
    English `title`/`description` on every property
- [x] Implement
- [x] Test

## 2. Schema: inbound lifecycle gate + RBAC

- **spec_ref**: `openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#requirement-lvsresult-inbound-verification-gate`
- **files**: `lib/Settings/learniq_register.json`, `lib/Lifecycle/LvsResultVerifyGuard.php`
- **acceptance_criteria**:
  - GIVEN an `LvsResult` created by the import handler
  - WHEN it is created
  - THEN its lifecycle starts at `imported` and only reaches `verified` via a guarded transition
  - GIVEN a non admin/coordinator actor attempts the `verify` transition
  - WHEN the guard runs
  - THEN it denies the transition
- [x] Implement
- [x] Test

## 3. Schema: `lvs-results` job target + DataMappingProfile seed

- **spec_ref**: `openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#requirement-lvs-results-job-type-and-payload-mapping`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN a `lvs-results` (direction: import) profile is added
  - THEN its `sourceSchema` is `assessment-result`, `targetSchema` names the UWLR result shape, and
    `fieldMappings` covers provider/instrument/moment/scores per the schema above
- [x] Implement
- [x] Test

## 4. Register + guard tests

- **spec_ref**: `openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#requirement-persist-lvsresult-linked-to-assessmentresult`
- **files**: `tests/Unit/Settings/LvsResultRegisterTest.php`, `tests/Unit/Lifecycle/LvsResultVerifyGuardTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON
  - WHEN it is parsed
  - THEN `LvsResult`'s shape, lifecycle, and RBAC assertions hold, and the guard denies/allows per role
- [x] Implement
- [x] Test

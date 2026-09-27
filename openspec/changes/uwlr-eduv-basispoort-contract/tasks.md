# Tasks: uwlr-eduv-basispoort-contract

## 1. Schema: target catalogue descriptions

- **spec_ref**: `openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#requirement-uwlr-and-edu-v-job-types-and-payload-mappings`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `DataExchangeJob.target` and `DataMappingProfile.target`'s descriptions
  - WHEN this change lands
  - THEN both name `uwlr`, `edu-v`, `basispoort`, and `entree-content` as valid connections
- [x] Implement
- [x] Test

## 2. Schema: UWLR pupil/group/teacher export + results import seeds

- **spec_ref**: `openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#requirement-uwlr-and-edu-v-job-types-and-payload-mappings`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN the four `uwlr` seeds are added
  - THEN pupil/group/teacher exports carry `eckId` where applicable and the results-import seed's
    `sourceSchema` is `lvs-result`
- [x] Implement
- [x] Test

## 3. Schema: Edu-V qualified-data-service export seeds

- **spec_ref**: `openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#requirement-uwlr-and-edu-v-job-types-and-payload-mappings`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN the three `edu-v` seeds are added
  - THEN each names its own qualified data service (`Onderwijsdeelnemers`/`Onderwijsgroepen`/
    `Onderwijsmedewerkers`) as a distinct `targetSchema`
- [x] Implement
- [x] Test

## 4. Schema: Basispoort (PO) and Entree content SSO (VO) hand-off seeds

- **spec_ref**: `openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#requirement-basispoort-and-entree-content-sso-hand-off`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN the `basispoort` and `entree-content` seeds are added
  - THEN both are `direction: sync` and carry `eckId`
- [x] Implement
- [x] Test

## 5. Register tests

- **spec_ref**: `openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#requirement-uwlr-and-edu-v-job-types-and-payload-mappings`
- **files**: `tests/Unit/Settings/UwlrEduvBasispoortRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON
  - WHEN it is parsed
  - THEN all nine seeds resolve with the field coverage above
- [x] Implement
- [x] Test

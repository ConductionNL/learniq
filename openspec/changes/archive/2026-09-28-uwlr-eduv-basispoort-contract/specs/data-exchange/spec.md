# Data Exchange — UWLR, Edu-V, Basispoort, Entree Content Contract Delta

**Spec refs**: `data-exchange`, findings `13.3`, `13.4`, `5.5`

## ADDED Requirements

### Requirement: UWLR and Edu-V job types and payload mappings

The system MUST ship `DataMappingProfile` seeds for `target: uwlr` covering pupil export
(`sourceSchema: learner-profile`, carrying `eckId`), group export (`sourceSchema: cohort`), and teacher
export (`sourceSchema: learner-profile`, carrying `eckId`), plus a `direction: import` seed whose
`sourceSchema` is `lvs-result` for UWLR's generic results-back data-service direction (deliberately reusing
`LvsResult` from `lvs-import-contract` rather than a second results schema — both name the same UWLR
transport). The system MUST additionally ship three `target: edu-v` export seeds, one per qualified data
service (`Onderwijsdeelnemers`, `Onderwijsgroepen`, `Onderwijsmedewerkers`), since Edu-V qualifies
certification per data service, per product, not once per connection.

#### Scenario: UWLR pupil and teacher exports carry ECK iD

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `uwlr` pupil and teacher export profiles are loaded
- **THEN** both map `eckId` as a `fieldMappings` entry

<!-- @e2e exclude Declarative seed-data shape verified by
     UwlrEduvBasispoortRegisterTest::testUwlrPupilAndTeacherExportsCarryEckId; no DOM surface. -->

#### Scenario: The UWLR results-import seed reuses LvsResult

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `uwlr` (direction: import) profile is loaded
- **THEN** its `sourceSchema` is `lvs-result`

<!-- @e2e exclude Declarative seed-data shape verified by
     UwlrEduvBasispoortRegisterTest::testUwlrResultsImportReusesLvsResult; no DOM surface. -->

#### Scenario: Edu-V ships one export seed per qualified data service

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the three `edu-v` seeds are loaded
- **THEN** each names a distinct `targetSchema` (`EduV:Onderwijsdeelnemers`, `EduV:Onderwijsgroepen`,
  `EduV:Onderwijsmedewerkers`)

<!-- @e2e exclude Declarative seed-data shape verified by
     UwlrEduvBasispoortRegisterTest::testEduVSeedsCoverThreeDataServices; no DOM surface. -->

### Requirement: Basispoort and Entree content SSO hand-off

The system MUST ship a `target: basispoort` (`direction: sync`) `DataMappingProfile` seed for the PO
pupil/group/staff export plus SSO hand-off to method/publisher content, and a separate `target:
entree-content` (`direction: sync`) seed for VO's Entree-based content-access hand-off, per
`M3-integrations.md`'s PO/VO split (Basispoort is PO-only; VO uses Edu-V/UWLR for data and Entree for content
SSO). Both are distinct from `entree-surfconext-sso-contract`'s own federated-login concern — these seeds
hand a pupil off to a THIRD-PARTY method/publisher site, not learniq's own authentication boundary.

#### Scenario: Basispoort and Entree content seeds are sync, not one-way export

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `basispoort` and `entree-content` profiles are loaded
- **THEN** both declare `direction: sync`

<!-- @e2e exclude Declarative seed-data shape verified by
     UwlrEduvBasispoortRegisterTest::testBasispoortAndEntreeContentAreSync; no DOM surface. -->

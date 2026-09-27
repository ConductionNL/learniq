# Data Exchange — LVS Result Import Contract Delta

**Spec refs**: `data-exchange`, `assessment`, findings `6.5`, `L-new-1`

## MODIFIED Requirements

### Requirement: Delegate wire protocols to OpenConnector

Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, UWLR, or SAML/OAuth attribute-release wire
protocols. Those MUST be OpenConnector source/target configurations referenced by the `target` field. In
addition to the existing named targets, `target` MUST support `lvs-results` (direction: `import`) for
Cito/IEP/Boom/Dia normed test results carried over UWLR — the OpenConnector adapter for this target is
tracked separately as `integriq-adapter-lvs-imports`; Scholiq implements no UWLR wire code itself.

#### Scenario: Delegate the wire send to OpenConnector

- **GIVEN** a `DataExchangeJob` with a `target` referencing an OpenConnector connection
- **WHEN** the job runs
- **THEN** Scholiq hands the payload to the OpenConnector source/target configuration and implements no
  wire protocol itself

#### Scenario: Course-management catalog publication delegates through the same DataExchangeJob mechanism

- **GIVEN** a `Course` or `Programme` transitions to `published` or `archived`
- **WHEN** `course-management`'s catalog-publication contract queues the catalog sync
- **THEN** it does so as a `DataExchangeJob` with `target: ooapi-catalog`
- **AND** Scholiq implements no OOAPI wire protocol itself — the OpenConnector `ooapi-catalog` adapter and
  opencatalogi's public OOAPI 5.0 endpoint handle the wire send and public exposure

#### Scenario: Delegate the LVS results import to OpenConnector

- **GIVEN** a `DataExchangeJob` with `target: lvs-results`, `direction: import`
- **WHEN** the job runs
- **THEN** Scholiq hands the inbound payload to the OpenConnector `lvs-results` source configuration and
  implements no UWLR wire protocol itself

## ADDED Requirements

### Requirement: Persist LvsResult linked to AssessmentResult

The system MUST persist `LvsResult` as an append-only OpenRegister object: `provider` (enum `cito | iep |
boom | dia`), `instrument`, `moment` (the LVS meetmoment code, e.g. `M6`/`E3`), `takenAt` (calendar date),
`rawScore` (nullable), `vaardigheidsscore` (nullable), `niveau` (nullable), `referentieniveau` (nullable),
`dle` (nullable), `learnerId`, `assessmentResultId` (nullable `$ref AssessmentResult` — set only when the
school also ran the same toets as an in-app `Assessment`), `dataExchangeJobId` (`$ref DataExchangeJob`),
`tenant_id`. `x-property-rbac.read` MUST restrict reads to `admin` or the learner whose `learnerId` matches
the requesting user, mirroring `AssessmentResult`'s own read restriction — no dedicated LVS-coordinator role
exists in this register.

#### Scenario: An imported LVS result links to an existing AssessmentResult when one exists

- **GIVEN** a `DataExchangeJob` with `target: lvs-results` imports a Cito result for a learner who also has
  a matching in-app `AssessmentResult`
- **WHEN** the `LvsResult` is created
- **THEN** `assessmentResultId` is set to that `AssessmentResult`'s id

<!-- @e2e exclude Pure OpenRegister schema/persistence shape, no DOM surface; verified by
     LvsResultRegisterTest::testAssessmentResultLinkIsNullable. -->

#### Scenario: A learner can read their own LVS results but not another learner's

- **GIVEN** an authenticated learner who is not `admin`
- **WHEN** they read an `LvsResult` whose `learnerId` does not match their own user id
- **THEN** the read is denied by `x-property-rbac`, consistent with `AssessmentResult`'s equivalent
  restriction

<!-- @e2e exclude RBAC enforcement is OpenRegister-core, declarative x-property-rbac, same scope boundary
     already used by AssessmentResult and ExchangeRejection's equivalent assertions. -->

### Requirement: LvsResult inbound verification gate

An imported `LvsResult` MUST start in an `imported` lifecycle state and MUST NOT be readable as verified
report-card/trend input until an `admin`/`coordinator` actor transitions it to `verified` via
`LvsResultVerifyGuard`. This is the D3 "lifecycle gate" every contract change declares for its own
direction: an automated UWLR/file-drop import is not itself proof the row is trustworthy, and a human
confirms it once, mirroring `AssessmentResult`'s own `submit → graded` human-confirmation shape.
`LvsResultVerifyGuard` MUST deny the transition for any actor not in the `admin`/`coordinator` groups.

#### Scenario: An imported result is not verified until a coordinator confirms it

- **GIVEN** an `LvsResult` created by the `lvs-results` import handler
- **WHEN** it is created
- **THEN** its lifecycle state is `imported`, not `verified`

<!-- @e2e exclude Declarative lifecycle initial-state shape verified by
     LvsResultRegisterTest::testInitialLifecycleStateIsImported; no DOM surface. -->

#### Scenario: A non admin/coordinator actor cannot verify an LvsResult

- **GIVEN** an authenticated user who is not in the `admin`/`coordinator` groups
- **WHEN** they attempt the `verify` transition on an `LvsResult`
- **THEN** `LvsResultVerifyGuard` denies the transition

<!-- @e2e exclude Role-gate logic verified by PHPUnit LvsResultVerifyGuardTest::testDeniesNonCoordinator,
     mirroring RejectionResubmitGuardTest's coverage shape; no scholiq DOM surface for the guard itself. -->

### Requirement: lvs-results job type and payload mapping

The system MUST ship a `DataMappingProfile` seed for `target: lvs-results`, `direction: import`,
`sourceSchema: assessment-result` (the UWLR-carried result is mapped onto the learniq side via the same
`fieldMappings` mechanism the Zermelo/Untis/Xedule import seeds already use for `direction: import`), naming
`targetSchema` as the external UWLR/Cito result shape and mapping `provider`, `instrument`, `moment`,
`rawScore`, `vaardigheidsscore`, `niveau`, `referentieniveau`, and `dle`.

#### Scenario: The lvs-results mapping profile declares the normed-score fields

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `lvs-results` profile is loaded
- **THEN** its `fieldMappings` cover `provider`, `instrument`, `moment`, `rawScore`, `vaardigheidsscore`,
  `niveau`, `referentieniveau`, and `dle`

<!-- @e2e exclude Declarative seed-data shape verified by LvsResultRegisterTest::testLvsResultsMappingProfileSeedShape;
     no DOM surface — mirrors the existing Zermelo/Untis/Xedule seed-shape assertions. -->

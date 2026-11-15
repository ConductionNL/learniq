# Data Exchange — OSO Inbound Import Contract Delta

**Spec refs**: `data-exchange`, findings `3.5`, `3.6`

## ADDED Requirements

### Requirement: Persist OsoImportDossier for inbound overstapdossiers

The system MUST persist `OsoImportDossier` as an OpenRegister object representing one received OSO
overstapdossier: `dataExchangeJobId` (`$ref DataExchangeJob`), `sourceSchoolBrin`, `learnerEckId` (nullable),
`receivedAt`, `categories` (array of `{category, included, data}`, `category` an illustrative,
non-authoritative starter enum of Besluit-style gegevensblokken — the authoritative Besluit uitwisseling
category list is a legal-review follow-up, not fabricated here), `draftProfile` (nullable object snapshot of
proposed `LearnerProfile` fields — NOT a live `LearnerProfile`), `attachmentRefs` (array of nc:files paths),
`rejectionReason` (nullable), `reviewedBy`/`reviewedAt` (nullable), `tenant_id`. The system MUST NOT
auto-materialise a `LearnerProfile` from an accepted dossier — a coordinator completes that through the
existing object UI, mirroring `LearningRecordImport`'s "evidence-only, coordinator acts through the existing
mechanism" posture (`portable-learning-record`).

#### Scenario: An incoming overstapdossier lands as a reviewable draft, not a live LearnerProfile

- **GIVEN** a `DataExchangeJob` with `target: oso`, `direction: import` receives an overstapdossier
- **WHEN** the `OsoImportDossier` is created
- **THEN** its `draftProfile` holds the proposed learner fields and its `categories` record which
  gegevensblokken were included, and no `LearnerProfile` object is created or modified as a side effect

<!-- @e2e exclude Pure OpenRegister schema/persistence shape, no DOM surface; verified by
     OsoImportDossierRegisterTest::testDraftProfileIsSnapshotNotLiveWrite. -->

### Requirement: OSO import is reviewed before acceptance

An `OsoImportDossier` MUST start in a `received` lifecycle state and MUST NOT reach `accepted` or `rejected`
without passing through `under-review`. `accept` MUST require `OsoImportAcceptGuard` (admin/coordinator
only) and stamps `reviewedBy`/`reviewedAt` server-side. `reject` MUST require `OsoImportRejectGuard`
(admin/coordinator only) and MUST refuse the transition when `rejectionReason` is empty, mirroring
`RejectionWaiveGuard`'s `waiveReason` enforcement. This is the D3 "lifecycle gate" every contract change
declares for its own inbound direction — an OSO import is not itself proof the transferred data is correct
or complete for this school's record, and a human confirms it once.

#### Scenario: A received dossier is not accepted until a coordinator reviews it

- **GIVEN** an `OsoImportDossier` created by the `oso` (direction: import) job handler
- **WHEN** it is created
- **THEN** its lifecycle state is `received`, not `accepted`

<!-- @e2e exclude Declarative lifecycle initial-state shape verified by
     OsoImportDossierRegisterTest::testInitialLifecycleStateIsReceived; no DOM surface. -->

#### Scenario: A non admin/coordinator actor cannot accept or reject an OsoImportDossier

- **GIVEN** an authenticated user who is not in the `admin`/`coordinator` groups
- **WHEN** they attempt the `accept` or `reject` transition on an `OsoImportDossier`
- **THEN** the corresponding guard denies the transition

<!-- @e2e exclude Role-gate logic verified by PHPUnit OsoImportAcceptGuardTest::testDeniesNonCoordinator and
     OsoImportRejectGuardTest::testDeniesNonCoordinator, mirroring MunicipalityFeedbackGuardTest's coverage
     shape; no scholiq DOM surface for either guard. -->

#### Scenario: Rejecting without a reason is refused

- **GIVEN** an `OsoImportDossier` in status `under-review`
- **WHEN** an admin/coordinator attempts the `reject` transition with an empty `rejectionReason`
- **THEN** the transition is refused

<!-- @e2e exclude Validation logic verified by PHPUnit OsoImportRejectGuardTest::testEmptyReasonRefused,
     mirroring RejectionWaiveGuardTest's equivalent test. -->

### Requirement: oso target supports the import direction

The system MUST ship a `DataMappingProfile` seed for `target: oso`, `direction: import`, `sourceSchema:
oso-import-dossier`, mapping the incoming OSO XML's learner/school identity fields
(`leerlingEckId`/`voornamen`/`achternaam`/`geboortedatum`/BRIN of the sending school) onto
`OsoImportDossier`'s fields, following the same `direction: import` convention the `timetable-import` seeds
already use (`scholiqField` names the learniq side, `targetField` the external side).

#### Scenario: The oso import mapping profile declares the sending school's identity fields

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `oso` (direction: import) profile is loaded
- **THEN** its `fieldMappings` cover `learnerEckId` and `sourceSchoolBrin`

<!-- @e2e exclude Declarative seed-data shape verified by
     OsoImportDossierRegisterTest::testOsoImportMappingProfileSeedShape; no DOM surface. -->

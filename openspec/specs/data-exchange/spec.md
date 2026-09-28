---
slug: data-exchange
title: Data Exchange — Export/Import Jobs to External Registries
status: done
feature_tier: should
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [bron-rod-duo, oso-po-vo, leerplicht-digikoppeling, surfconext-attributes, hr-system]
replaces_thin_slice_of: [bron-rod-exchange, oso-transfer, identity-federation]
---

# Data Exchange

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, OpenConnector delegation, and audit-trail emission — no `#### Scenario:` headings exist in this spec.

## Purpose

An institution's data has to flow to and from external systems: a Dutch school's `leveringsverplichting` to **DUO BRON/ROD**, a pupil's **OSO** transfer dossier PO→VO, a `leerplichtmelding` to the municipality over **Digikoppeling**, **SURFconext** attribute mapping for HE login, a corporate **HR-system** sync for who must do which mandatory training. These are real and non-negotiable for the relevant buyers — but they are **integration adapters**, not Scholiq schemas. Scholiq's job is to (a) expose its data (`LearnerProfile`, `Enrolment`, `GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Credential`, `Attestation`…) in a mappable form, (b) hold a small `DataExchangeJob` queue so a user can *request* an export/import and watch it, and (c) record every exchange in the audit trail (ADR-008). The actual wire protocols (Edukoppeling, StUF, OSO XML, OOAPI, OAuth/SAML attribute release) live in **OpenConnector** source/target configurations — separate issues filed against `ConductionNL/openconnector`. Federated *authentication* (DigiD / SURFconext / eduID) is likewise an OpenConnector + Nextcloud-auth concern: Scholiq only stores the resulting pseudonymous identifiers, which `LearnerProfile` already carries (`eckId`, `schoolId`, `bsnEncrypted`).

## What

- **DataExchangeJob** — a request: `direction` (`export` | `import` | `sync`), `target` (a named OpenConnector connection — `bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`), `scope` (which objects / which cohort / which period), `format` (resolved by the target), `lifecycle` (`queued → running → succeeded | failed | partial`), `result` (counts, validation report, the produced artefact reference), `requestedBy`, timestamps. The job *delegates* to OpenConnector — it does not implement the protocol.
- **DataMappingProfile** — declares how a Scholiq schema maps to a target schema (`{ scholiqField → targetField, transform }[]`) for a given `target`. The Dutch BRON/ROD, OSO, and leerplicht mappings are profiles shipped (or downloadable) with the app; an HR mapping is configured by the admin. Validation runs against the target's schema before the job leaves the queue.
- **The OSO dossier composer** — for `target=oso`, the job assembles the transfer dossier from existing `LearnerProfile` + `GradeEntry` + `AttendanceRecord` + `LearningPlan` data, presents it for parent review (a parent must approve before it's sent — `lifecycle` gains a `pending-parent-review` state), then hands the approved XML to OpenConnector for the Edukoppeling send. The receiving VO LAS imports it as a `DataExchangeJob` with `direction=import`.
- Audit: every `DataExchangeJob` state transition emits an OR audit-trail entry; the job's produced artefact (the XML, the report) is retained as an OR file attachment for the legally-required period.

## User Stories

- As a school administrator, I want to send the term's pupil + enrolment data to BRON, see the validation report, and know the `leveringsverplichting` is met — without leaving Scholiq.
- As a PO mentor at the sending school, I want to compose a pupil's OSO dossier from existing data, have the parent review and approve it, and then transfer it to the receiving VO school.
- As a VO mentor at the receiving school, I want to import an incoming OSO dossier into the pupil's LearnerProfile with one click.
- As an attendance coordinator, I want a crossed leerplicht threshold to queue a `leerplichtmelding` to the municipality (Digikoppeling) and show me whether it was accepted.
- As a corporate L&D admin, I want a nightly HR-system sync that creates/retires `LearnerProfile`s and sets who's in which mandatory-training audience.

## Acceptance Criteria

- GIVEN a `DataExchangeJob` `{direction:export, target:bron-rod, scope:<cohort>}`, WHEN it runs, THEN Scholiq builds the payload from the `DataMappingProfile`, hands it to the OpenConnector `bron-rod` connection, and the job lifecycle becomes `succeeded` (or `partial` with a per-record validation report) — Scholiq itself implements no Edukoppeling/StUF wire code.
- GIVEN an OSO export job, WHEN the dossier is composed, THEN the job sits in `pending-parent-review` until the parent approves; only then does it move to `running` and the send proceed.
- GIVEN an incoming OSO dossier, WHEN a VO mentor imports it, THEN the matching `LearnerProfile` is updated (or created) and an audit entry records the import source.
- GIVEN an `AttendanceThreshold` with `onCross` targeting `leerplicht`, WHEN a flag is created (see `attendance`), THEN a `DataExchangeJob` is auto-queued to the `leerplicht` target and the flag's lifecycle tracks it.
- GIVEN any `DataExchangeJob`, WHEN it changes state, THEN an OR audit-trail entry is emitted and the produced artefact is attached to the job for retention.

## Requirements

### Requirement: Federated authentication is out of scope
Federated authentication (DigiD / SURFconext / eduID) is OUT of this spec — it MUST be handled by a Nextcloud-auth-provider + OpenConnector; Scholiq only persists the pseudonymous identifiers on `LearnerProfile` (already does).

#### Scenario: Store only pseudonymous identifiers
- **GIVEN** a learner authenticated via DigiD / SURFconext / eduID
- **WHEN** the authentication completes outside this spec's scope
- **THEN** Scholiq persists only the resulting pseudonymous identifiers on `LearnerProfile` and does not implement the federated authentication itself

### Requirement: Data-exchange management is reached from the Admin Settings page
The data-exchange entry point MUST move from the in-app settings foldout to the Nextcloud Admin Settings page. The `DataExchange` leaf id MUST be removed from `src/menu-layout.json#settingsSection`, and the Admin Settings page (mounted by `lib/Settings/AdminSettings.php` + `src/settings.js` → `src/views/settings/AdminRoot.vue`) MUST render a "Data exchange" settings section that links to the still-routable Data-exchange **jobs** (`#/data-exchange/jobs`) and **mapping profiles** (`#/data-exchange/mapping-profiles`) SPA pages, mirroring the "Manage AI features" affordance in `ScholiqSettings.vue`. Because the Admin Settings mount has no in-app vue-router, the links MUST navigate out via full navigation (hash-form SPA URL), not by embedding router pages. All data-exchange pages (`DataExchangeJobs`, `DataExchangeJobDetail`, `DataMappingProfiles`, `DataMappingProfileDetail`, `RequestExportModal`, `OsoDossierReviewView`) MUST remain registered in `src/manifest.json.pages[]` and routable. No backend, register schema, lifecycle guard, OSO gate or OpenConnector delegation is changed.

#### Scenario: Admin Settings shows a Data exchange section
<!-- @e2e exclude Admin Settings is rendered by the Nextcloud settings framework outside the SPA route-smoke harness (tests/e2e/pages.spec.ts); the section render + link targets are verified in-browser at apply. -->
- **GIVEN** an admin on the Scholiq Admin Settings page (`AdminRoot.vue`)
- **WHEN** the page renders
- **THEN** a "Data exchange" settings section is shown with a link to Data-exchange jobs (`#/data-exchange/jobs`) and a link to mapping profiles (`#/data-exchange/mapping-profiles`)

#### Scenario: The in-app Data exchange foldout entry is removed
<!-- @e2e exclude Static / absence assertion — verified by the manifest/menu-layout unit test (no `DataExchange` id in settingsSection); not a positive route-smoke DOM behaviour. -->
- **GIVEN** the parsed `src/menu-layout.json`
- **WHEN** its `settingsSection` array is inspected
- **THEN** it does not list `DataExchange`

#### Scenario: Data-exchange jobs page remains routable via deep link
- **GIVEN** the `DataExchangeJobs` page is no longer in the nav
- **WHEN** a user navigates directly to `#/data-exchange/jobs`
- **THEN** the `DataExchangeJobs` index page renders without a fatal error

#### Scenario: Data-exchange mapping profiles page remains routable via deep link
- **GIVEN** the `DataMappingProfiles` page is no longer in the nav
- **WHEN** a user navigates directly to `#/data-exchange/mapping-profiles`
- **THEN** the `DataMappingProfiles` index page renders without a fatal error

### Requirement: A DataExchangeJob target can require standing partner approval before it runs
`DataExchangeJob` SHALL gain four additive properties: `requiresPartnerApproval` (boolean, default `false`),
`partnerApprovalStatus` (enum `not-required | pending | approved | rejected`, default `not-required`),
`partnerApprovedBy`, `partnerApprovedAt`, and `dataSharedFields` (array of strings naming which fields this
target pulls). `DataExchangeRunGuard::check()` SHALL deny the `run` transition (`queued → running`) when
`requiresPartnerApproval === true` and `partnerApprovalStatus !== 'approved'`, independently of the existing
OSO/SWV parent-review gate — a job may be subject to either gate, both, or neither. Every existing job defaults
to `requiresPartnerApproval: false`, so no previously-running target is newly blocked by this change.

#### Scenario: A job for a partner-gated target cannot run before approval
- **GIVEN** a `DataExchangeJob` with `target: "uwlr"`, `requiresPartnerApproval: true`, `partnerApprovalStatus: "pending"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition is refused

#### Scenario: Approval unblocks the run transition
- **GIVEN** the same job with `partnerApprovalStatus` updated to `"approved"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition succeeds (subject to any other applicable gate, e.g. OSO/SWV)

#### Scenario: A job with no partner-approval requirement is unaffected
- **GIVEN** a `DataExchangeJob` with `requiresPartnerApproval: false` (the default)
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the partner-approval condition never blocks it

<!-- @e2e exclude Pure backend/data-model requirement, per this spec's own "no #### Scenario DOM assertions" convention for guard logic — verified by DataExchangeRunGuardTest and PrivacyGovernanceRegisterTest (schema shape). -->

### Requirement: A DataExchangeJob target can require a confirmed teldatum pre-flight check before it runs
`DataExchangeJob` SHALL gain five additive properties: `requiresTeldatumCheck` (boolean, default `false`),
`teldatumCheckStatus` (enum `not-required | pending | confirmed`, default `not-required`), `teldatumCheckDate`
(nullable date, the 1 February or 1 October count date), `teldatumCheckedBy`, `teldatumCheckedAt` (both nullable).
`DataExchangeRunGuard::check()` SHALL deny the `run` transition when `requiresTeldatumCheck === true` and
`teldatumCheckStatus !== 'confirmed'`, independently of every other condition on the same guard. Every existing job
defaults to `requiresTeldatumCheck: false`, so no previously-running target is newly blocked.

#### Scenario: A ROD job requiring a teldatum check cannot run before confirmation
- **GIVEN** a `DataExchangeJob` with `target: "bron-rod"`, `requiresTeldatumCheck: true`, `teldatumCheckStatus: "pending"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition is refused

#### Scenario: Confirmation unblocks the run transition
- **GIVEN** the same job with `teldatumCheckStatus` updated to `"confirmed"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition succeeds (subject to any other applicable gate)

#### Scenario: A job with no teldatum-check requirement is unaffected
- **GIVEN** a `DataExchangeJob` with `requiresTeldatumCheck: false` (the default)
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the teldatum-check condition never blocks it

<!-- @e2e exclude Pure backend/data-model requirement, per this spec's own "no #### Scenario DOM assertions" convention for guard logic — verified by DataExchangeRunGuardTest and FundingTeldatumRegisterTest (schema shape). -->

### Requirement: Pupil, cohort, report-card, and attendance indexes declare columns and explicit built-in mass-action toggles

`LearnerProfiles`, `Cohorts`, `ReportCards`, and `AttendanceRecords` MUST each declare `columns`
(a curated subset of already-existing fields, replacing the generic default column set) and
`actionToggles` with `showMassImport: true`, `showMassExport: true`, `showMassCopy: true`, and
`showMassDelete: true` — making `CnIndexPage`'s existing built-in mass-action capability an
explicit, documented feature of these four indexes rather than an implicit platform default.

#### Scenario: The four indexes expose their curated columns and explicit mass-action toggles

<!-- @e2e exclude Declarative manifest column/actionToggles addition, no new component behaviour to exercise; verified by reasoning over the built effective manifest (build_effective_manifest.js), mirroring how report-card-templates and care-and-support-index (sibling changes this round) verified their own manifest-only additions. -->

- **GIVEN** the manifest is built
- **WHEN** `LearnerProfiles`, `Cohorts`, `ReportCards`, and `AttendanceRecords` are each inspected
- **THEN** each declares a non-empty `columns` array and `actionToggles.showMassImport`/
  `showMassExport`/`showMassCopy`/`showMassDelete` all `true`

### Requirement: An Import & export nav entry makes the existing data-exchange tooling discoverable

The system MUST declare an `ImportExportToolsMenu` nav entry under the existing `GroupDataExchange`
menu group, labelled "Import & export", routing to the existing `DataExchangeJobs` page — no new
page is declared, per ADR-097 Decision 5's "a second index over an already-indexed schema is a
role lens, not a page" preference (already applied by `care-and-support-index`, a sibling change
this round, for the same reason).

#### Scenario: The Import & export entry routes to the existing DataExchangeJobs page

<!-- @e2e exclude Declarative manifest nav addition, no new route or component; verified by reasoning over the built effective manifest. -->

- **GIVEN** the manifest is built
- **WHEN** `ImportExportToolsMenu` is inspected
- **THEN** it routes to `DataExchangeJobs`, and no second `type: "index"` page exists over the
  `data-exchange-job` schema

### Requirement: TimeEdit joins the rostering-import preset family

The system MUST ship a `DataMappingProfile` seed named `TimeEdit timetable import` for `target:
timetable-import`, `direction: import`, `sourceSchema: session`, matching the shape the existing
Zermelo/Untis/Xedule seeds already use (externalRef/cohortId/title/startsAt/endsAt/location mapped to
TimeEdit's own field names).

#### Scenario: TimeEdit matches the existing rostering-import seed shape

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `TimeEdit timetable import` seed is loaded
- **THEN** it declares `target: timetable-import`, `direction: import`, `sourceSchema: session`, and maps
  the same field set (`externalRef`, `cohortId`, `title`, `startsAt`, `endsAt`, `location`) the
  Zermelo/Untis/Xedule seeds already map

<!-- @e2e exclude Declarative seed-data shape verified by
     DataMappingProfilePresetsRegisterTest::testTimeEditMatchesExistingRosteringSeedShape; no DOM surface —
     mirrors the existing Zermelo/Untis/Xedule seed-shape assertions. -->

### Requirement: migration-import job type and payload mappings

The system MUST support a new `target: migration-import` (`direction: import`) on `DataExchangeJob` and
`DataMappingProfile`, and MUST ship one `DataMappingProfile` seed per migration source system (ParnasSys,
ESIS, Magister, SOMtoday), each `sourceSchema: learner-profile`, mapping at minimum `eckId`, `givenName`,
`familyName`, `birthDate`, and `schoolId`.

#### Scenario: Each migration source ships its own preset carrying ECK iD

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the ParnasSys, ESIS, Magister, and SOMtoday seeds are loaded
- **THEN** each is `target: migration-import`, `direction: import`, `sourceSchema: learner-profile`, and
  maps `eckId`

<!-- @e2e exclude Declarative seed-data shape verified by
     DataMappingProfilePresetsRegisterTest::testEachMigrationSourceCarriesEckId; no DOM surface. -->

#### Scenario: DataExchangeJob.target documents the migration-import connection

- **GIVEN** `DataExchangeJob.target`'s description
- **WHEN** this change lands
- **THEN** it names `migration-import` as a valid connection

<!-- @e2e exclude Declarative documentation-string shape verified by
     DataMappingProfilePresetsRegisterTest::testDataExchangeJobTargetDescribesMigrationImport; no DOM
     surface. -->

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

### Requirement: The connection field names every connection learniq hands to OpenConnector

The `target` property of `DataExchangeJob` and of `DataMappingProfile` MUST name, in its description, every connection learniq defines: `bron-rod`, `oso` (with its inbound direction), `leerplicht`, `surfconext`, `hr`, `swv`, `timetable-import`, `migration-import`, `lvs-results`, `uwlr`, `edu-v`, `basispoort` and `entree-content`, and the `DataExchangeJob` description MUST also name `ooapi-catalog`. Each description MUST have an English and a Dutch catalogue entry.

#### Scenario: A coordinator reads which connections exist
@e2e exclude Register-content invariant; pinned by tests/Unit/Settings/UwlrEduvBasispoortRegisterTest.php (testTargetDescriptionsNameNewConnections), LvsResultRegisterTest and OsoImportDossierRegisterTest.
- **GIVEN** the shipped register
- **WHEN** the `target` field of a new data exchange job is shown
- **THEN** its help text names lvs-results, the inbound oso direction, uwlr, edu-v, basispoort, entree-content and migration-import

### Requirement: Register tests find seed rows by identity and assert floors

A test that reads a schema's seed rows MUST find a row by its name or id and MUST assert a minimum count, never an exact count or a position, because other changes append seed rows to the same list.

#### Scenario: A sibling change adds a mapping preset
@e2e exclude Test-suite invariant; pinned by tests/Unit/Settings/DataMappingProfilePresetsRegisterTest.php and UwlrEduvBasispoortRegisterTest.php.
- **GIVEN** 23 `DataMappingProfile` seed rows, more than the 12 and 16 two changes counted
- **WHEN** the suite runs
- **THEN** both tests pass, because they assert a floor

### Requirement: Learniq asks integriq to carry an exchange
Learniq MUST request every data exchange through integriq's `ExchangeJobRequestedEvent`, with its own id as owner, the row that caused it as `ownerRef` and selectors only in the scope. It MUST fail closed when integriq is absent: no job, and a message that integriq is needed. Learniq MUST NOT store a job of its own.

#### Scenario: an attendance flag asks for a leerplicht report
- GIVEN integriq is installed and an attendance threshold's crossing names the target `leerplicht`
- WHEN the flag is created
- THEN learniq dispatches `ExchangeJobRequestedEvent` for `leerplicht`, `export`, mapping `learniq-leerplicht-export-melding`
- AND the flag's `dataExchangeJobId` is the integriq job id

#### Scenario: integriq is absent
- GIVEN integriq is not installed
- WHEN a support request is submitted
- THEN no job id is stored and no dossier review is created
- AND the failure is logged, not thrown into the submit

### Requirement: The gate refuses an OSO or SWV file until a parent approved it
For an `oso` or `swv` export, learniq's gate MUST refuse unless a `DossierReview` for that integriq job is `approved`. Only a parent listed on the learner's profile MUST be able to approve or reject it.

#### Scenario: no parent approved yet
- GIVEN an `swv` job whose `DossierReview` is `pending`
- WHEN integriq asks the gate
- THEN the gate refuses with `parent-review-pending`

#### Scenario: a parent approves
- GIVEN a `DossierReview` for learner L and a user listed in L's `parentIds`
- WHEN that user approves it
- THEN it is `approved` with `reviewedBy` and `reviewedAt` stamped, and the gate allows the job

### Requirement: The gate enforces partner approval, teldatum confirmation and flag handling
The gate MUST refuse a job whose target has an `ExchangePartnerApproval` row and none `approved`; a job whose scope names a `teldatumDate` without a `confirmed` `TeldatumCheck` for that date and target; and a `leerplicht` job whose attendance flag is still `open`. A target with no partner approval row MUST NOT be blocked by partner approval.

#### Scenario: a partner link awaits approval
- GIVEN an `ExchangePartnerApproval` for `swv` in status `pending`
- WHEN integriq asks the gate for an `swv` job whose file a parent approved
- THEN the gate refuses with `partner-approval-missing`

#### Scenario: the teldatum is not confirmed
- GIVEN a `bron-rod` job whose scope names `teldatumDate` 2026-10-01 and no confirmed check for it
- WHEN integriq asks the gate
- THEN the gate refuses with `teldatum-unconfirmed`

#### Scenario: nobody took up the flag
- GIVEN a `leerplicht` job whose attendance flag is `open`
- WHEN integriq asks the gate
- THEN the gate refuses with `flag-not-in-handling`

### Requirement: What may leave is decided by learniq, per mapping
When every other condition passes, the gate MUST hand integriq only the fields the job's mapping reads, per record `{recordId, sourceKind, data}`, with the leerplicht and SWV files composed as before, and never `bsnEncrypted`, `bsnHash` or `email`. A statutory target (`bron-rod`, `oso`, `swv`) without a known mapping MUST be refused `disclosure-undefined`. A `bron-rod`, `leerplicht` or `oso` record missing a statutory field MUST refuse the job `statutory-incomplete`, naming fields and references, never values.

#### Scenario: a ROD export hands over five fields
- GIVEN a `bron-rod` job with mapping `learniq-bron-rod-export-learner` over two complete learner profiles
- WHEN integriq asks the gate
- THEN the gate allows with two records whose data holds only `eckId`, `givenName`, `familyName`, `birthDate`, `schoolId`

#### Scenario: a statutory export without a mapping
- GIVEN a `bron-rod` job for a school advice with no mapping
- WHEN integriq asks the gate
- THEN the gate refuses with `disclosure-undefined` and composes no record

#### Scenario: a record misses its birth date
- GIVEN a `bron-rod` job whose second learner has no `birthDate`
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete`, naming `birthDate` and that learner's reference

### Requirement: Learniq serves its gate decision over HTTP for people
`GET /api/exchange-gates/{jobId}` MUST answer `{jobId, decision, code, reason, checkedAt}` for an integriq job owned by learniq, without the records, to admins, administration managers, compliance officers and coordinators; 404 for a job that is not learniq's or when integriq is absent.

#### Scenario: a coordinator checks why a job waits
- GIVEN an integriq job owned by learniq whose teldatum is unconfirmed
- WHEN a coordinator calls `GET /api/exchange-gates/{jobId}`
- THEN the answer is `refuse` with `teldatum-unconfirmed` and no records

### Requirement: The Data exchange menu is a read-only status panel beside the gate pages
The Data exchange menu MUST show integriq's jobs and rejections owned by learniq, read-only, and the partner approval, teldatum check and dossier review pages. It MUST NOT offer to create or edit a job.

#### Scenario: an administrator opens the panel
- GIVEN integriq holds two jobs owned by learniq and one owned by another app
- WHEN an administrator opens Exchange jobs
- THEN two rows show, without an add or edit action

### Requirement: Existing exchange rows are moved to integriq, or archived
A repair step MUST archive every row of the four retired schemas to app data, and when integriq is installed MUST send each job (with its rejections) and each customised mapping profile to integriq, once. It MUST NOT delete the old rows.

#### Scenario: an install with integriq
- GIVEN two learniq jobs, one with a waived rejection, and integriq installed
- WHEN the repair step runs
- THEN integriq receives two job requests with history, the archive file holds both jobs and the rejection, and a second run sends nothing

#### Scenario: an install without integriq
- GIVEN learniq jobs and no integriq
- WHEN the repair step runs
- THEN the archive file holds every row and nothing is dispatched

### Requirement: A succeeded SWV exchange routes its support request
When integriq concludes a learniq `swv` job `succeeded`, learniq MUST move the support request named in the job's scope to `routed-to-swv`; any other outcome MUST change nothing.

#### Scenario: the SWV hand-off succeeded
- GIVEN a submitted support request whose `swv` job integriq concluded `succeeded`
- WHEN the concluded event arrives
- THEN the support request is `routed-to-swv`

### Requirement: Imported LVS results and transfer dossiers are read and written by the groups that review them

`LvsResult` and `OsoImportDossier` MUST each carry an `authorization` block that OpenRegister enforces. Read MUST be granted to `coordinators` and `compliance-officers`, and on `LvsResult` also to the learner the row is about, as `{"group": "authenticated", "match": {"learnerId": "$userId"}}`. `OsoImportDossier` MUST NOT carry a learner self-read, because its `learnerEckId` is not a Nextcloud user. Create and update MUST be granted to `coordinators` and `compliance-officers` only, and neither block MAY grant delete. The `verify` guard of `LvsResult` and the `accept` and `reject` guards of `OsoImportDossier` MUST authorise `admin` and `coordinators`, the group the register declares, and MUST refuse any other group, including a group literally named `coordinator`. `LvsResult` MUST NOT be `appendOnly`, so `verify` and `archive` can run.

#### Scenario: A coordinator verifies an imported LVS result
@e2e exclude Enforced by OpenRegister from the shipped register JSON and the guard; pinned by tests/Unit/Register/ImportRecordAccessTest.php and tests/Unit/Lifecycle/LvsResultVerifyGuardTest.php.
- **GIVEN** an `LvsResult` in `imported`
- **AND** a user in `coordinators`
- **WHEN** the user fires `verify`
- **THEN** the result moves to `verified`

#### Scenario: A pupil reads their own LVS result and not a classmate's
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/ImportRecordAccessTest.php and tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **GIVEN** pupils A and B, in no staff group, each with an `LvsResult`
- **WHEN** pupil A lists LVS results
- **THEN** only A's own result is returned

#### Scenario: An instructor no longer reads transfer dossiers
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/ImportRecordAccessTest.php.
- **GIVEN** a received `OsoImportDossier`
- **AND** a user in `instructors` only
- **WHEN** the user lists transfer dossiers
- **THEN** the dossier is not returned

#### Scenario: The singular coordinator group accepts nothing
@e2e exclude Guard behaviour with no UI of its own; pinned by tests/Unit/Lifecycle/OsoImportAcceptGuardTest.php.
- **GIVEN** an `OsoImportDossier` in `under-review`
- **AND** a user in a group named `coordinator`, which the register does not declare
- **WHEN** the user fires `accept`
- **THEN** the guard refuses it

### Requirement: A dossier received without an exchange job is valid

`OsoImportDossier.dataExchangeJobId` MUST accept `null` for a dossier entered by hand, as `LvsResult.dataExchangeJobId` does. Every `null` in a demo object of the learniq register MUST sit on a property declared nullable.

#### Scenario: The demo dossiers pass their own schema
@e2e exclude Register declaration with no UI; pinned by tests/Unit/Register/DemoNullsAreNullableTest.php::testEveryDemoNullIsOnANullableProperty.
- **GIVEN** the demo OsoImportDossier rows with `dataExchangeJobId` null
- **WHEN** they are validated against the schema
- **THEN** they pass

### Requirement: A verified LVS score cannot be changed

Once an `LvsResult` is stored as `verified` or `archived`, an update by a signed-in user who is not a Nextcloud admin MUST NOT change `provider`, `instrument`, `moment`, `takenAt`, `rawScore`, `vaardigheidsscore`, `niveau`, `referentieniveau`, `dle`, `dataExchangeJobId` or `tenant_id`. A verified result MUST only move on to `archived`, and an archived result MUST NOT change state. `learnerId` and `assessmentResultId` MAY still change. An `imported` result MAY be corrected. Admins and system context are not held to this rule.

#### Scenario: A coordinator cannot change a verified score
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testCoordinatorCannotChangeAVerifiedScore.
- **GIVEN** an `LvsResult` in `verified` with `vaardigheidsscore` 187
- **AND** a user in `coordinators`
- **WHEN** the user saves it with `vaardigheidsscore` 201
- **THEN** the save is refused with reason `lvs-result-verified`

#### Scenario: An imported score may still be corrected
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testAnImportedScoreMayBeCorrected.
- **GIVEN** an `LvsResult` in `imported`
- **WHEN** a coordinator corrects its `vaardigheidsscore`
- **THEN** the save goes through

#### Scenario: A verified result cannot go back to imported
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testAVerifiedResultCannotGoBackToImported.
- **GIVEN** an `LvsResult` in `verified`
- **WHEN** a coordinator saves it as `imported`
- **THEN** the save is refused with reason `lvs-result-lifecycle`
- **AND** saving it as `archived` goes through

### Requirement: Records integriq hands back for an import land in learniq

When integriq dispatches `ExchangeRecordsReceivedEvent` for a job owned by `learniq` with target `lvs-results`, `oso` or `migration-import`, learniq MUST land each record and MUST call `accept()` exactly once, with the number of records taken and a rejection per record it did not take, naming an error code and field names but never a value. An LVS result MUST land as an `imported` LvsResult and MUST NOT change a verified or archived one; an OSO dossier MUST land as a `received` OsoImportDossier held for review; a migration record MUST fill only empty fields of the pupil's LearnerProfile, or create an active profile. A second delivery of the same record MUST change nothing already taken. Events for another owner or target MUST be left unanswered.

#### Scenario: An LVS result lands as imported
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testLvsResultsLandAsImported.
- **GIVEN** an lvs-results import job with three records, one for a known pupil, one for an unknown pupil and one without instrument and moment
- **WHEN** integriq hands the records to learniq
- **THEN** one LvsResult is stored in `imported` with the job's id
- **AND** learniq accepts 1 and rejects `LVS-UNKNOWN-PUPIL` and `LVS-MISSING-FIELD` with the field names

#### Scenario: A second delivery changes nothing already taken
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testASecondDeliveryIsIdempotent.
- **GIVEN** a verified LvsResult
- **WHEN** the same record is delivered again with another score
- **THEN** nothing is written and the record counts as taken

#### Scenario: An OSO dossier is held for review
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testAnOsoDossierIsHeldForReview.
- **GIVEN** an oso import job with a dossier from school 12AB
- **WHEN** integriq hands it to learniq
- **THEN** an OsoImportDossier is stored in `received`

#### Scenario: A migrated pupil fills only the gaps of their profile
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testMigrationFillsOnlyTheGaps.
- **GIVEN** a LearnerProfile with a first name and no family name
- **WHEN** a migration record brings another first name, a family name and roles
- **THEN** only the family name is written

### Requirement: The voorlopig school advice goes to ROD when it is given

When a SchoolAdvies in `voorlopig` has `voorlopigAdviesLevel`, `voorlopigAdviesDate` and `learnerId` and no `voorlopigExchangeJobId`, learniq MUST ask integriq for a `bron-rod` export with berichtsoort `schooladvies`, the school advice mapping and the advice as its only record, outside the save that made it due, and MUST record the returned job id as `voorlopigExchangeJobId` so the voorlopig advice is sent once.

#### Scenario: A voorlopig advice goes to ROD when it is given
@e2e exclude Deferred exchange request with no UI of its own; pinned by tests/Unit/Listener/SchoolAdviesVoorlopigRodTest.php.
- **GIVEN** a SchoolAdvies in `voorlopig`
- **WHEN** its advice level and date are saved
- **THEN** a bron-rod schooladvies job is requested for it
- **AND** its id is stored as `voorlopigExchangeJobId`
- **AND** saving the advice again requests nothing

### Requirement: A learner's personal number is encrypted and readable only by administration and compliance
LearnerProfile MUST hold the persoonsgebonden nummer in `personalNumber`, flagged
`x-openregister-encrypted: true`, with its kind in `personalNumberType` (`bsn` or
`onderwijsnummer`). Both properties MUST carry a property authorization whose `read` and `update`
name only `administration-managers` and `compliance-officers`, and `personalNumber` MUST ask for a
reveal audit (`audit: true`). Neither property MAY be a filter, facet or search field.

#### Scenario: a teacher reads a learner profile
- GIVEN a learner profile with a `personalNumber`
- WHEN a user in `instructors` reads it
- THEN the response has no `personalNumber` and no `personalNumberType`

#### Scenario: the register declares the protection
- GIVEN the learniq register
- WHEN LearnerProfile is loaded
- THEN `personalNumber` is flagged encrypted, and both properties authorize only `administration-managers` and `compliance-officers`

### Requirement: The ROD learner record carries the personal number where DUO expects a BSN
A `bron-rod` job with mapping `learniq-bron-rod-export-learner` MUST hand each record
`persoonsgebondenNummer` (nine digits) and `persoonsgebondenNummerType` (`burgerservicenummer` or
`onderwijsnummer`) next to `eckId`, `givenName`, `familyName`, `birthDate` and `schoolId`. A number
that is not nine digits or fails its check (elfproef for a BSN, the adapted elfproef for an
onderwijsnummer) MUST count as missing, and the gate MUST refuse the job `statutory-incomplete`
naming the field and the record reference, never the value.

#### Scenario: a ROD export sends the BSN and keeps the ECK iD
- GIVEN a `bron-rod` job with mapping `learniq-bron-rod-export-learner` over a complete profile whose `personalNumber` is a valid BSN
- WHEN integriq asks the gate
- THEN the record holds `persoonsgebondenNummer` with that BSN, `persoonsgebondenNummerType` `burgerservicenummer`, and the `eckId`

#### Scenario: a profile without a valid number
- GIVEN a `bron-rod` learner job whose profile has no `personalNumber`
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete`, naming `persoonsgebondenNummer` and the record, and the reason holds no number

### Requirement: The personal number leaves learniq only in a ROD message and is never logged
No mapping other than the two ROD mappings MAY carry `personalNumber`, `personalNumberType`,
`persoonsgebondenNummer` or `persoonsgebondenNummerType`; a pass-through export MUST drop
`personalNumber` and `personalNumberType` as it drops `bsnEncrypted`. No log line, exception message
or refusal reason written by the gate, the payload builder or the resolver MAY contain the number.

#### Scenario: an HR pass-through export
- GIVEN an `hr` export (no mapping) over a profile with a `personalNumber`
- WHEN the gate composes the records
- THEN no record holds `personalNumber`, `personalNumberType` or the number anywhere in its data

#### Scenario: an OSO export
- GIVEN an `oso` job with mapping `learniq-oso-export-dossier`
- WHEN the gate composes the records
- THEN no record holds the number

#### Scenario: nothing is logged
- GIVEN a ROD job whose composition succeeds, and one whose profile read fails
- WHEN the gate runs both
- THEN no captured log message or context value contains the number

### Requirement: A school advice goes to ROD with DUO's AanleverenAdviesVO field set
The school advice ROD handler MUST name mapping `learniq-bron-rod-export-schooladvies`, and the gate
MUST compose, per SchoolAdvies, exactly: `persoonsgebondenNummer`, `persoonsgebondenNummerType`,
`adviesvolgnummer`, `onderwijsaanbieder`, `onderwijslocatie`, `vestigingscode`, `adviesjaar`,
`advies1`, `advies1Datum`, `advies2`, `advies2Datum` (DUO PvE ROD-PO 1.14.2, 7.9.1). Levels MUST be
DUO's AdviesVO values (`pro` to `PRAKTIJKONDERWIJS`, `vmbo-bb` to `VMBO_BB`, `vmbo-kb` to `VMBO_KB`,
`vmbo-gt` to `VMBO_GL/TL`, `havo` to `HAVO`, `vwo` to `VWO`). Nothing else from the advice or the
pupil dossier MAY leave, in particular not the heroverweging motivation or the doorstroomtoets
result. The job MUST no longer be refused `disclosure-undefined`; a record missing
`persoonsgebondenNummer`, `adviesvolgnummer`, `onderwijsaanbieder`, `onderwijslocatie`,
`vestigingscode`, `adviesjaar`, `advies1` or `advies1Datum` MUST refuse it `statutory-incomplete`
(DUO checks the onderwijsaanbieder and onderwijslocatie from adviesjaar 2024, controls 030 and 031).
A value that does not fit DUO's format counts as missing.

#### Scenario: a definitief advice is sent
- GIVEN a SchoolAdvies with voorlopig `vmbo-kb` on 2026-01-20, definitief `vmbo-gt` on 2026-03-20, academic year `2025-2026`, a vestiging with code `02VG00`, and a learner with a valid BSN
- WHEN integriq asks the gate for its `bron-rod` job
- THEN the gate allows one record with `advies1` `VMBO_KB`, `advies1Datum` `2026-01-20`, `advies2` `VMBO_GL/TL`, `advies2Datum` `2026-03-20`, `adviesjaar` `2026`, `vestigingscode` `02VG00` and the BSN, and no other key

#### Scenario: the advice has no vestiging and the tenant has several
- GIVEN a SchoolAdvies without `vestigingId` in a tenant with two vestigingen
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete` naming `vestigingscode`

#### Scenario: the handler names the mapping
- GIVEN a SchoolAdvies moving to `verzonden-naar-rod`
- WHEN the handler asks integriq for the job
- THEN it names mapping `learniq-bron-rod-export-schooladvies`

## Standards

Edukoppeling / Digikoppeling / StUF (NL gov messaging — implemented in OpenConnector); OSO standard (Edu-K); DUO BRON/ROD schemas; OOAPI 5.0 (HE); SAML 2.0 / OIDC for SURFconext attribute release; SCIM for HR-system sync; eIDAS / DigiD for federated auth (out of scope here).

## Data Model

All in OpenRegister. New: `DataExchangeJob`, `DataMappingProfile`. Consumes: every Scholiq schema as a source. Delegates to: OpenConnector connections (configured separately). One ADR-031 PHP exception: the job-execution handler that invokes OpenConnector. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The OpenConnector adapters themselves (separate issues on `ConductionNL/openconnector`).
- Federated authentication (DigiD / SURFconext / eduID — NC auth + OpenConnector).
- Real-time webhook ingestion from external registries (jobs are pull/push on demand or on a schedule; streaming is a follow-up).
- Cross-tenant / SIVON federation of the data itself.

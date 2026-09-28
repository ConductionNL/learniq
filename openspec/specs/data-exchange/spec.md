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

### Requirement: Persist DataExchangeJob and DataMappingProfile in OpenRegister

The system MUST persist `DataExchangeJob`, `DataMappingProfile` as OpenRegister objects with
`x-openregister-lifecycle` (queued → running → succeeded | failed | partial; OSO adds
pending-parent-review), `x-openregister-relations`, `x-openregister-notifications` (job-done alert), and
audit-trail emission on every transition (ADR-008). `DataExchangeJob` artefacts MUST be OR file
attachments. `DataExchangeJob` MUST additionally persist a nullable `municipalityFeedback` — the
case-handling route (MAS-route) the municipality assigns a `leerplicht` report to, a received-at
timestamp, and a free-text note — recorded by an authorised coordinator once the municipality communicates
it. Scholiq records this feedback; it MUST NOT poll for, infer, or automate its ingestion (mirroring the
existing "record, don't adjudicate" posture already applied to other externally-decided outcomes on this
register). Every record `DataExchangeRunHandler` builds for OpenConnector MUST carry a `_scholiqRecordId`
correlation identifier (the source object's own `id`), so that a per-record rejection returned in
`result.validationReport` can be resolved back to the Scholiq object that produced it; `validationReport`'s
per-item shape MUST be documented as `{recordId, errorCode, errorMessage, field?}`.

#### Scenario: Persist a job and emit audit on transition

- **GIVEN** a `DataExchangeJob` request
- **WHEN** the job is created and changes lifecycle state
- **THEN** the system persists it as an OpenRegister object, emits an audit-trail entry on every
  transition, and attaches the produced artefact as an OR file attachment

<!-- @e2e exclude Pure OpenRegister persistence/audit-trail behaviour, unchanged by this delta; already covered by the existing data-exchange implementation, no new DOM surface. -->

#### Scenario: Coordinator records the municipality's route decision

- **GIVEN** a succeeded `DataExchangeJob` with `target: leerplicht`
- **WHEN** an authorised coordinator learns the municipality's case-handling route for the report and
  records it
- **THEN** `municipalityFeedback` is set with the route, a timestamp, and the coordinator's note, without
  Scholiq inferring or automating the decision, and an unauthorised user cannot set this field

<!-- @e2e exclude Unchanged by this delta; behaviour already shipped by verzuim-report-composer. -->

#### Scenario: Every exported record carries a correlation identifier

- **GIVEN** a `DataExchangeJob` builds its payload for OpenConnector, with or without a `DataMappingProfile`
- **WHEN** `buildPayload()` produces each record
- **THEN** every record — whether field-mapped, pass-through, or dossier-composed (leerplicht/swv) — carries
  a `_scholiqRecordId` equal to the source object's own `id`

<!-- @e2e exclude Payload-construction logic in DataExchangeRunHandler::buildPayload(); verified by PHPUnit DataExchangeRunHandlerTest::testBuildPayloadStampsCorrelationIdWithProfile and ::testBuildPayloadStampsCorrelationIdWithoutProfile; no DOM surface — the payload never reaches the browser. -->

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

### Requirement: Federated authentication is out of scope
Federated authentication (DigiD / SURFconext / eduID) is OUT of this spec — it MUST be handled by a Nextcloud-auth-provider + OpenConnector; Scholiq only persists the pseudonymous identifiers on `LearnerProfile` (already does).

#### Scenario: Store only pseudonymous identifiers
- **GIVEN** a learner authenticated via DigiD / SURFconext / eduID
- **WHEN** the authentication completes outside this spec's scope
- **THEN** Scholiq persists only the resulting pseudonymous identifiers on `LearnerProfile` and does not implement the federated authentication itself

### Requirement: OSO parent-review is a lifecycle gate
The OSO parent-review gate MUST be a lifecycle state; the dossier MUST NOT leave the queue until parent approval is recorded.

#### Scenario: Hold an OSO dossier until parent approval
- **GIVEN** an OSO export job whose dossier has been composed
- **WHEN** the job awaits review
- **THEN** the job stays in `pending-parent-review` and does not leave the queue until parent approval is recorded

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for DataExchangeJob/DataMappingProfile index+detail; a custom `RequestExportModal` and `OsoDossierReviewView` Vue component. There MUST be no PHP CRUD controllers; the job execution is an OR-event-driven handler that calls OpenConnector (an ADR-031 "external-system bridge" exception, single method).

#### Scenario: Render the surface declaratively with named views
- **GIVEN** the data-exchange frontend
- **WHEN** it renders the DataExchangeJob/DataMappingProfile index and detail surfaces
- **THEN** it is driven by `src/manifest.json` with the custom `RequestExportModal` and `OsoDossierReviewView` views and ships no PHP CRUD controllers

### Requirement: Verzuimloket dossier composition mirrors the OSO dossier composer

For `target: leerplicht`, the job MUST compose its payload the same way the OSO dossier composer does (see
`attendance`'s "External authority reporting via DataExchangeJob"): from the originating `AttendanceFlag`
plus its breaching `AttendanceRecord`s and `interventions` history — not the bare summary-field export the
existing `Leerplicht notification export` `DataMappingProfile` currently ships. Unlike the OSO/SWV
dossiers, this composition MUST NOT gate on `pending-parent-review`: it is a mandatory Leerplichtwet
art. 21a report to the municipality, not a discretionary transfer requiring parent consent.

#### Scenario: Verzuimloket dossier is composed like the OSO dossier, without a parent-review gate

- **GIVEN** a `DataExchangeJob` with `target: leerplicht` auto-queued from an `AttendanceFlag`
- **WHEN** the job composes its payload
- **THEN** it assembles the dossier from the flag's breaching records and intervention history, and
  proceeds toward `running` via the same non-OSO path other targets use, without entering
  `pending-parent-review`

### Requirement: OSO-format dossier parent-review gate covers the SWV zorgvraag target too

The `pending-parent-review` lifecycle gate MUST NOT be limited to the `target: oso` PO→VO overstap case.
Any `DataExchangeJob` whose composed dossier is OSO-format — including `target: swv` jobs composed from a
`SupportRequest` (see `learning-plan`) — MUST pass through the identical `pending-parent-review` gate
before the send proceeds. This MUST be the same lifecycle mechanism (`OsoDossierReviewGuard`-equivalent
guard on the `approveDossier` transition), not a second, parallel review mechanism.

#### Scenario: SWV zorgvraag dossier gated identically to the OSO overstap dossier

- **GIVEN** a `DataExchangeJob` with `target: swv` whose OSO-format care-request dossier has been composed
  from a `SupportRequest`
- **WHEN** the job awaits review
- **THEN** it enters `pending-parent-review` and does not leave the queue until parent approval is
  recorded, using the same gate mechanism as the `target: oso` PO→VO overstap flow

### Requirement: Persist ExchangeRejection mapped from job rejections

The system MUST persist `ExchangeRejection` as an OpenRegister object with one row per rejected record from
a `DataExchangeJob`'s `result.validationReport`: the originating `dataExchangeJobId`, DUO's `errorCode` and
`errorMessage`, a best-effort `errorCodeRef` into the `ExchangeErrorCode` catalogue, any `offendingFields`
derivable from the connector's response, a `sourceKind` (`learner-profile | enrolment | final-grade |
attendance-flag | support-request`) plus the correspondingly-typed nullable `$ref` id field identifying the
Scholiq object the rejection maps back to, and a `status` lifecycle (`open → corrected → resubmitted →
accepted | open`, or `open|corrected → waived`). Read access MUST be restricted to `admin`/`principal` roles
(no dedicated data-exchange-coordinator role exists in this register). `ExchangeRejection` MUST be created
exclusively by a listener — never through the generic object-create UI.

#### Scenario: A rejected record is persisted with its source-object reference

- **GIVEN** a `DataExchangeJob` result containing a `validationReport` entry with a `recordId` matching an
  exported `LearnerProfile`
- **WHEN** the rejection is mapped
- **THEN** an `ExchangeRejection` is created with `sourceKind: learner-profile`, `learnerProfileId` set to
  that `LearnerProfile`'s id, `status: open`, and the DUO `errorCode`/`errorMessage` copied verbatim

<!-- @e2e exclude Object-creation logic in the new RejectionMappingHandler; verified by PHPUnit RejectionMappingHandlerTest::testCreatesRejectionWithSourceKind; no DOM surface for object creation itself. -->

#### Scenario: An unauthorised user cannot read rejection detail

- **GIVEN** an authenticated user who is not `admin` or `principal`
- **WHEN** they attempt to read an `ExchangeRejection` object
- **THEN** the read is denied by `x-property-rbac`, consistent with `SupportRequest`'s equivalent read
  restriction

<!-- @e2e exclude RBAC enforcement is OpenRegister-core behaviour driven by a declarative x-property-rbac block, same scope boundary as every other x-property-rbac assertion in this register (verified via schema-declaration tests, not live authorization flows from this repo). -->

### Requirement: Resolve a job's rejected records to their Scholiq source object

The system MUST resolve each `result.validationReport` entry's `recordId` back to the Scholiq object that
produced it, using the job's own `scope.schema` to determine which `sourceKind` applies, and MUST NOT create
duplicate `ExchangeRejection` rows for the same `(dataExchangeJobId, recordId)` pair on repeated handler
invocation (idempotency). When a `DataExchangeJob` is itself a resubmission (referenced by an
`ExchangeRejection.resubmittedJobId`), the system MUST update the originating rejection rather than create a
new row: transition it to `accepted` when its `recordId` no longer appears in the new job's
`validationReport`, or back to `open` with the fresh `errorCode`/`errorMessage` when it still does.

#### Scenario: Idempotent mapping on repeated handler invocation

- **GIVEN** a `DataExchangeJob` has already been mapped into `ExchangeRejection` rows
- **WHEN** the mapping handler is invoked again for the same job (e.g. a redelivered event)
- **THEN** no duplicate `ExchangeRejection` rows are created for any `(dataExchangeJobId, recordId)` pair
  already mapped

<!-- @e2e exclude Idempotency logic verified by PHPUnit RejectionMappingHandlerTest::testDoesNotDuplicateOnRedelivery; no DOM surface. -->

#### Scenario: A resubmitted record that DUO now accepts closes its rejection

- **GIVEN** an `ExchangeRejection` in status `resubmitted` whose `resubmittedJobId` points at a
  `DataExchangeJob` that has just finished
- **WHEN** that job's `recordId` no longer appears in the new job's `result.validationReport`
- **THEN** the `ExchangeRejection` transitions to `accepted`

<!-- @e2e exclude Resubmission-outcome logic verified by PHPUnit RejectionMappingHandlerTest::testResubmittedRecordAcceptedClosesRejection; no DOM surface. -->

#### Scenario: A resubmitted record DUO rejects again reopens its rejection

- **GIVEN** an `ExchangeRejection` in status `resubmitted` whose `resubmittedJobId` points at a
  `DataExchangeJob` that has just finished
- **WHEN** that job's `result.validationReport` still contains an entry for the same `recordId`
- **THEN** the `ExchangeRejection` transitions back to `open` and its `errorCode`/`errorMessage` are updated
  to the new rejection's values

<!-- @e2e exclude Resubmission-outcome logic verified by PHPUnit RejectionMappingHandlerTest::testResubmittedRecordStillRejectedReopens; no DOM surface. -->

### Requirement: Inline correction worklist with per-rejection resubmission

The frontend MUST expose an `ExchangeRejections` index and `ExchangeRejectionDetail` page (declarative
`src/manifest.json`, no custom Vue component) where the detail page's `related` widget resolves whichever
`sourceKind` `$ref` field is set into a deep link to the offending object's own detail page.
`lifecycleActions` MUST render `Mark corrected` (`open → corrected`), `Resubmit` (`corrected →
resubmitted`), and `Waive` (`open|corrected → waived`) from the declared lifecycle. `Resubmit` MUST be
guarded by a role check (admin/coordinator) and, on success, MUST create exactly one new `DataExchangeJob`
scoped to only that rejection's source object (`scope.filters.id = sourceObjectId`, reusing the existing
generic filter mechanism — no batched multi-record resubmission). `Waive` MUST require a non-empty
`waiveReason`, mirroring `DeliberationRecord.pupilVoice`'s waived/waiverReason enforcement, and MUST stamp
`waivedBy`/`waivedAt` server-side.

#### Scenario: Admin deep-links from a rejection to the offending object

- **GIVEN** an `ExchangeRejection` with `sourceKind: learner-profile` and `learnerProfileId` set
- **WHEN** an admin opens the rejection's detail page
- **THEN** the `related` widget shows a link to that `LearnerProfile`'s own detail page

<!-- @e2e tests/e2e/spec-coverage/data-exchange.spec.ts -->

#### Scenario: Resubmit creates exactly one scoped job and stamps the link

- **GIVEN** an `ExchangeRejection` in status `corrected`, created by an admin/coordinator
- **WHEN** they trigger the `Resubmit` transition
- **THEN** exactly one new `DataExchangeJob` is created with `scope.filters.id` equal to the rejection's
  `sourceObjectId` and the same `target`/`mappingProfileId` as the original job, the rejection's
  `resubmittedJobId` is stamped to the new job's id, and the rejection moves to `resubmitted`

<!-- @e2e tests/e2e/spec-coverage/data-exchange.spec.ts -->

#### Scenario: A non-authorised user cannot resubmit or waive

- **GIVEN** an authenticated user not in the `admin`/`coordinator` groups
- **WHEN** they attempt the `Resubmit` or `Waive` transition on an `ExchangeRejection`
- **THEN** the guard denies the transition

<!-- @e2e exclude Role-gate logic verified by PHPUnit RejectionResubmitGuardTest / RejectionWaiveGuardTest, mirroring MunicipalityFeedbackGuardTest's coverage shape; no scholiq DOM surface for the guard itself. -->

#### Scenario: Waiving without a reason is refused

- **GIVEN** an `ExchangeRejection` in status `open` or `corrected`
- **WHEN** an admin/coordinator attempts the `Waive` transition with an empty `waiveReason`
- **THEN** the transition is refused

<!-- @e2e exclude Validation logic verified by PHPUnit RejectionWaiveGuardTest::testEmptyReasonRefused, mirroring PupilVoiceGuard's equivalent test. -->

### Requirement: Track rejection age as an informational urgency signal

The system MUST expose a materialised `ageDays` calculation on `ExchangeRejection` (days since `detectedAt`).
`correctionDeadlineAt` MUST be a plain nullable input field, not a computed fixed-offset deadline — no
statutory day-count for BRON/ROD afkeurmelding correction is fabricated. When `correctionDeadlineAt` is set,
`overdue` MUST be a materialised calculation derived from it (`correctionDeadlineAt <= now AND status NOT IN
(accepted, waived)`); when unset, `overdue` MUST be `false`.

#### Scenario: Age is always available regardless of a deadline

- **GIVEN** an `ExchangeRejection` with no `correctionDeadlineAt` set
- **WHEN** its `ageDays` calculation is read
- **THEN** it returns the number of days since `detectedAt`, and `overdue` is `false`

<!-- @e2e exclude Declarative calculation shape verified by ExchangeRejectionRegisterTest::testAgeDaysCalculationShape / testOverdueNullSafeWhenDeadlineUnset, mirroring AttendanceFlag's own calculation-shape test pattern; calculation execution itself runs in OpenRegister core, not Scholiq PHP. -->

#### Scenario: Overdue activates once a deadline is set and passes

- **GIVEN** an `ExchangeRejection` with `correctionDeadlineAt` set to a past date and `status: open`
- **WHEN** `overdue` is evaluated
- **THEN** it is `true`

<!-- @e2e exclude Declarative calculation shape verified by ExchangeRejectionRegisterTest::testOverdueTrueWhenDeadlinePassed; calculation execution runs in OpenRegister core. -->

### Requirement: DUO error-code catalogue as local reference data

The system MUST persist `ExchangeErrorCode` as OpenRegister reference-data objects (`code`, optional
`target`, bilingual `description`, optional `category`, `severity`, `active`) seeded with a starter set
explicitly documented as illustrative, non-authoritative starter data. `RejectionMappingHandler` MUST
attempt to resolve each created `ExchangeRejection.errorCodeRef` by matching `(code, target)` against this
catalogue, and MUST leave `errorCodeRef` null when no match exists rather than blocking rejection creation.
The system MUST NOT claim this catalogue is authoritative — updates to DUO's real code list are an
OpenConnector adapter concern, alongside the wire-protocol adapters `data-exchange`'s "Delegate wire
protocols to OpenConnector" requirement already lists.

#### Scenario: A known error code resolves to its catalogue entry

- **GIVEN** an `ExchangeErrorCode` seeded with `code: "BRON-101"`, `target: "bron-rod"`
- **WHEN** a rejection with `errorCode: "BRON-101"` from a `target: bron-rod` job is mapped
- **THEN** the created `ExchangeRejection.errorCodeRef` points at that catalogue entry

<!-- @e2e exclude Catalogue-lookup logic verified by PHPUnit RejectionMappingHandlerTest::testResolvesKnownErrorCode; no DOM surface. -->

#### Scenario: An unknown error code does not block rejection creation

- **GIVEN** no `ExchangeErrorCode` entry matches a rejection's `(errorCode, target)`
- **WHEN** the rejection is mapped
- **THEN** the `ExchangeRejection` is still created, with `errorCodeRef` left null

<!-- @e2e exclude Fail-open catalogue-lookup behaviour verified by PHPUnit RejectionMappingHandlerTest::testUnknownErrorCodeLeavesRefNull; no DOM surface. -->

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

## Standards

Edukoppeling / Digikoppeling / StUF (NL gov messaging — implemented in OpenConnector); OSO standard (Edu-K); DUO BRON/ROD schemas; OOAPI 5.0 (HE); SAML 2.0 / OIDC for SURFconext attribute release; SCIM for HR-system sync; eIDAS / DigiD for federated auth (out of scope here).

## Data Model

All in OpenRegister. New: `DataExchangeJob`, `DataMappingProfile`. Consumes: every Scholiq schema as a source. Delegates to: OpenConnector connections (configured separately). One ADR-031 PHP exception: the job-execution handler that invokes OpenConnector. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The OpenConnector adapters themselves (separate issues on `ConductionNL/openconnector`).
- Federated authentication (DigiD / SURFconext / eduID — NC auth + OpenConnector).
- Real-time webhook ingestion from external registries (jobs are pull/push on demand or on a schedule; streaming is a follow-up).
- Cross-tenant / SIVON federation of the data itself.

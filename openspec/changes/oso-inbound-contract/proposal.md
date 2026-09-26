---
kind: code
depends_on: []
---

## Why

`findings.md#3.6` (MUST): "OSO import into the receiving school's record" — verified at HEAD, the nearest
hit is the `oso` **export** job type in `RolloverExecutionService`; there is no OSO import path at all.
`M3-integrations.md` I3 confirms the same gap from the connection side: `learniq today` is "job type `oso`,
parent-review gate, review view is a dark page (m1#3.5)" — export-only — against `parnassys` ("full export
... + import flow"), `po-las` (esis: "Inlezen in ESIS"), `vo-las` (magister: "Leerling registreren vanuit het
OSO dossier"). Every PO/VO competitor in the round names the import half; learniq has never built it.

D3 (`decisions.md`) settles who builds what: learniq declares the job type, payload mapping, and lifecycle
gate; the wire adapter (the actual OSO XML receive/parse over Edukoppeling) is integriq's, tracked as
`integriq-adapter-oso` (which also needs `registry-component-fix` (D01, the dark `OsoDossierReviewView`) and
the Kennisnet OSO aansluiting approval per `M3-integrations.md`'s governance note (M3c) before it can go
live — this change ships no wire code and is not gated on either).

`recon/legal-po-2026-09-25.md` and `parnassys/round1/documented-column.md#3.5` both describe the OSO
transfer as structured into required and optional **gegevensblokken** (data blocks) under the Besluit
uitwisseling leer- en begeleidingsgegevens, with a parent-facing PDF for inzage and a recorded
informed/objection trail on the SENDING side (`OsoDossierReviewGuard`, already shipped). The RECEIVING side
has no equivalent structure at all today — an import cannot even name which data blocks it received, let
alone hold them for review before they touch a real `LearnerProfile`.

Following `data-exchange`'s own established idiom (`OsoDossierReviewGuard` on export,
`DataExchangeRunGuard`'s `GATED_TARGETS` allowlist) and `LearningRecordImport`'s "evidence-only, coordinator
acts through the existing mechanism" precedent (`portable-learning-record` capability — a coordinator who
wants to act on an entries[] report does so through `ExemptionCase`, never an automatic cross-schema write):
this change lands an incoming overstapdossier as a reviewable `OsoImportDossier` record, never as a direct,
unreviewed write into `LearnerProfile`. A coordinator reviews the categories and attachments, then accepts
(at which point they create/complete the real `LearnerProfile` through the existing UI — this change does
not add an auto-materialisation listener, matching `LearningRecordImport`'s own scope boundary) or rejects.

Per ADR-032, `config` is JSON-only; this change adds two small PHP guard classes
(`OsoImportAcceptGuard`/`OsoImportRejectGuard`), so — same reasoning as `lvs-import-contract` — it is typed
`kind: code` here even though `change-plan.md`'s shorthand calls the row `config`.

## What Changes

- Add `DataExchangeJob.target = 'oso'` inbound support: `direction: import` is now a documented, valid
  combination for the existing `oso` target (today's description and seed only cover the PO→VO export
  case) — extend the target description accordingly. No enum change needed (`target` is free-form).
- Add a `DataMappingProfile` seed (`target: oso`, `direction: import`, `sourceSchema:
  oso-import-dossier`, `targetSchema: OSO:TransferDossier`) mapping the incoming OSO XML's fields
  (`leerlingEckId`, `voornamen`, `achternaam`, `geboortedatum`, `brinNummer` of the sending school) onto
  `OsoImportDossier`'s fields, mirroring the existing `timetable-import` seeds' `direction: import`
  convention (scholiqField still names our side, targetField the external side).
- Add `OsoImportDossier` (new schema): `dataExchangeJobId` (`$ref DataExchangeJob`), `sourceSchoolBrin`,
  `learnerEckId` (nullable), `receivedAt`, `categories` (array of `{category, included, data}` — `category`
  an illustrative, non-authoritative starter enum of Besluit-style gegevensblokken: `basisgegevens |
  onderwijskundig-rapport | uitstroomgegevens | toetsgegevens | verzuimgegevens | zorggegevens`, explicitly
  documented as illustrative starter data the same way `ExchangeErrorCode` documents its own catalogue —
  the authoritative Besluit category list is a legal-review follow-up, not fabricated here), `draftProfile`
  (nullable object snapshot of proposed `LearnerProfile` fields — `givenName`/`familyName`/`birthDate`/
  `eckId`/`schoolId` — NOT a live `LearnerProfile`; mirrors `LearningRecordImport`'s "evidence-only" posture:
  a coordinator who accepts creates/completes the real `LearnerProfile` through the existing object UI, no
  auto-materialisation listener in this change), `attachmentRefs` (array of nc:files paths, mirroring
  `PortfolioEntry.attachmentRef`/`Material.fileRef` naming — the onderwijskundig-rapport PDF etc.),
  `rejectionReason` (nullable), `reviewedBy`/`reviewedAt` (nullable, stamped server-side), `tenant_id`.
- Add `x-openregister-lifecycle` to `OsoImportDossier`: `received` (initial) → `under-review`
  (`startReview`, unguarded) → `accepted` (`requires: OCA\Learniq\Lifecycle\OsoImportAcceptGuard`,
  admin/coordinator, stamps `reviewedBy`/`reviewedAt`) | `rejected` (`requires:
  OCA\Learniq\Lifecycle\OsoImportRejectGuard`, admin/coordinator, requires a non-empty `rejectionReason`,
  mirroring `RejectionWaiveGuard`'s reason-enforcement shape). This is the "reviewed before acceptance" gate
  D3 asks every contract to declare for its own inbound direction.
- Add `x-property-rbac.read` to `OsoImportDossier`: admin/coordinator only (internal intake review, not the
  learner's own record yet — no `LearnerProfile` exists for this learner in this tenant until accepted).
- New `lib/Lifecycle/OsoImportAcceptGuard.php` and `lib/Lifecycle/OsoImportRejectGuard.php`, both mirroring
  `MunicipalityFeedbackGuard`'s role-check-plus-stamp shape (accept stamps `reviewedBy`/`reviewedAt`; reject
  additionally requires `rejectionReason`, mirroring `RejectionWaiveGuard`'s `waiveReason` enforcement).

## Impact

- Affected specs: `data-exchange` (MODIFIED: `oso` target description now covers import; ADDED:
  `OsoImportDossier` persistence + inbound review-gate requirements).
- Affected code: `lib/Settings/learniq_register.json` (new schema, new seed, `DataExchangeJob.target`/
  `DataMappingProfile.target` description updates), new `lib/Lifecycle/OsoImportAcceptGuard.php`, new
  `lib/Lifecycle/OsoImportRejectGuard.php`, new `tests/Unit/Settings/OsoImportDossierRegisterTest.php`, new
  `tests/Unit/Lifecycle/OsoImportAcceptGuardTest.php`, new `tests/Unit/Lifecycle/OsoImportRejectGuardTest.php`.
- No integriq adapter code (`integriq-adapter-oso`, separate, also depends on `registry-component-fix` and
  the Kennisnet OSO aansluiting approval per M3c — neither blocks this change).
- No auto-materialisation of `LearnerProfile` from an accepted dossier in this change — a coordinator
  completes that through the existing object UI, matching `LearningRecordImport`'s own scope boundary. A
  follow-up change may add a listener once the pattern is confirmed against real OSO XML shapes.

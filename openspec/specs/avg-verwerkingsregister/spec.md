---
status: in-progress
openspec_changes:
  - privacy-reuse-openregister-register
---

# avg-verwerkingsregister Specification

## Purpose
Provides Scholiq's GDPR Article 30 processing-activities register (verwerkingsregister), declaring its processing catalogue — learner administration, attendance and leerplicht reporting, grading, compliance training, credentialing, data exchange, and AI features — as draft seed content that a privacy officer activates. The register slice is browsable through a declarative Compliance UI served over the platform's verwerkingsactiviteiten API, and its platform-generated Art. 30 export is included in the compliance audit pack. All storage, RBAC, review reminders, and export logic are owned by OpenRegister; Scholiq contributes only the seed catalogue and UI surface.

## Requirements

### Requirement: Scholiq MUST ship its processing catalogue as draft seed content

Scholiq SHALL declare its own processing activities via the `x-openregister-processing` dialect (OR-PA-2) in
`lib/Settings/scholiq_register.json` — at minimum: learner administration (LearnerProfile incl.
`bsnEncrypted`, `eckId`, `schoolId`, `address`, `emergencyContacts`, `medicalConditions`, `allergies`),
attendance and leerplicht reporting, grading and assessment, compliance training and signed attestations
(incl. `actorIp`), credentialing, data exchange (DUO/OSO/municipality/HR), AI features, and **everyday pupil
dossier notes, behaviour incidents, wellbeing check-ins, and first-aid incidents**
(`DossierNote`, `BehaviourIncident`, `WellbeingCheckIn`, `FirstAidIncident` — the `pupil-dossier` capability)
— each entry carrying the full Art. 30(1) field set keyed by `code`, plus
`ownerUserId`/`reviewIntervalMonths`/`nextReviewAt` so the platform's review-due notification (OR-PA-1)
fires. Seeds arrive as drafts; activation is the privacy officer's explicit decision in the platform
lifecycle. No seed entry copies personal-data values, and scholiq ships no notification rule, schema
definition, or validation code for this capability.

#### Scenario: Fresh install seeds the register as drafts

<!-- @e2e exclude OR-side seed-import mechanics (OR-PA-2); catalogue content verified by
     ProcessingActivityCatalogueTest. The admin compliance section deep-link is covered by
     avg-verwerkingsregister.spec.ts -->

- **GIVEN** a fresh scholiq install completing its register import
- **WHEN** the privacy officer opens the verwerkingsregister
- **THEN** the seeded entries are listed as drafts, including "Leerlingadministratie" naming BSN (encrypted),
  ECK iD, SchoolID, address, emergency contacts, medical conditions, and allergies among the personal-data
  categories
- **AND** the seeded entries also include the four `pupil-dossier` processing activities
  (`scholiq-pupil-dossier-notes`, `scholiq-behaviour-incidents`, `scholiq-wellbeing-checkins`,
  `scholiq-first-aid-incidents`)
- **AND** no seeded entry is active until an explicit platform-lifecycle activation

#### Scenario: Review reminders come from the platform

<!-- @e2e exclude OR-PA-1 review-due notification is OpenRegister's; the absence of a scholiq notification
     rule + presence of owner/review fields is verified by
     ProcessingActivityCatalogueTest::testOwnerReviewFieldsPresentAndNoScholiqNotificationRule -->

- **GIVEN** an activated entry whose `nextReviewAt` falls within the review window
- **WHEN** OpenRegister's review-due evaluation runs (OR-PA-1)
- **THEN** the entry's `ownerUserId` receives the notification
- **AND** `scholiq_register.json` contains no notification rule for processing-activity reviews

#### Scenario: Officer edits survive re-import

<!-- @e2e exclude upsert-by-code / never-overwrite-officer-edits is OR-PA-2 mechanics; asserted in
     OpenRegister's suite, not a scholiq UI surface -->

- **GIVEN** the privacy officer amended an activated entry
- **WHEN** scholiq's register configuration is re-imported
- **THEN** the upsert-by-code semantics (OR-PA-2) MUST preserve the officer's edits rather than resetting the
  entry to the seed

### Requirement: Scholiq MUST surface its register slice in declarative Compliance UI

Scholiq SHALL provide manifest index and detail pages for its verwerkingsregister slice under a Compliance navigation entry, consuming the platform verwerkingsactiviteiten API with the scholiq register filter (OR-PA-8 scoping). No PHP CRUD controllers and no app-side authorization code; write access is the platform's admin-default plus privacy-officer delegation.

#### Scenario: Privacy officer browses scholiq's register slice

<!-- @e2e tests/e2e/spec-coverage/avg-verwerkingsregister.spec.ts -->

- **GIVEN** a user holding the privacy-officer delegation in OpenRegister
- **WHEN** they open the Compliance → Verwerkingsregister page in scholiq
- **THEN** they see exactly the scholiq-slice activities (index + detail), served by manifest pages over the platform API

#### Scenario: Non-privileged user cannot edit

<!-- @e2e exclude access gating is OR-PA-8 (ProcessingLogController fails closed for non-admin/non-FG); asserted in OpenRegister's suite, not a scholiq UI surface (the section is admin-gated client-side as defence-in-depth) -->

- **GIVEN** an authenticated user without the privacy-officer or admin delegation
- **WHEN** they attempt to update a processing activity via the platform API
- **THEN** the request is rejected by OpenRegister's RBAC (OR-PA-8); scholiq enforces nothing itself

### Requirement: The compliance audit pack MUST include the platform-generated Art. 30 export

The compliance audit-pack ZIP SHALL include `verwerkingsregister.csv`, obtained from the platform's Art. 30 export (OR-PA-7) scoped to the scholiq register slice. Scholiq implements only the fetch-and-include artefact step — no export engine, serialisation, or column logic.

#### Scenario: Audit pack includes the register

<!-- @e2e exclude ZIP-stream backend artefact verified by ProcessingActivityCatalogueTest::testAuditPackIncludesVerwerkingsregisterAndFailsLoudly; no UI surface to drive -->

- **GIVEN** a compliance audit-pack export for any regulation and date range
- **WHEN** the ZIP is produced
- **THEN** it contains `verwerkingsregister.csv` whose content equals the OR-PA-7 export for the scholiq slice at generation time

#### Scenario: Platform capability absent fails loudly

<!-- @e2e exclude backend ZIP-stream warning path verified by ProcessingActivityCatalogueTest::testAuditPackIncludesVerwerkingsregisterAndFailsLoudly; no UI surface to drive -->

- **GIVEN** the deployed OpenRegister version lacks the processing-activity register
- **WHEN** an audit-pack export is requested
- **THEN** the pack generation MUST surface a clear "platform capability missing" warning for the verwerkingsregister artefact rather than silently omitting it

### Requirement: The school records its Privacyconvenant agreement and privacybijsluiter
The system SHALL provide a `Compliance` singleton schema (flat, un-lifecycled — mirrors `SovereigntyPolicy`'s
precedent) carrying `privacyconvenantSigned` (boolean), `privacyconvenantSignedAt`, `verwerkersovereenkomstUrl`,
`privacybijsluiterUrl`, `privacybijsluiterVersion`, `lastReviewedAt`, and `lastReviewedBy`. Create and update
SHALL be restricted to `compliance-officers`. This closes finding 15.5 (zero prior hits for a
verwerkersovereenkomst or privacybijsluiter anywhere in the app).

#### Scenario: A compliance officer records the signed Privacyconvenant agreement
- **GIVEN** a compliance officer opens the Compliance record
- **WHEN** they set `privacyconvenantSigned: true`, a `verwerkersovereenkomstUrl`, and a `privacybijsluiterUrl`
- **THEN** the record persists those fields and `lastReviewedAt`/`lastReviewedBy` reflect who made the change

<!-- @e2e exclude Schema shape and RBAC floor verified by PrivacyGovernanceRegisterTest; no bespoke controller — reads/writes go through OpenRegister's generic object endpoint per ADR-022, same as SovereigntyPolicy. -->

### Requirement: Staff can log and track a correction or deletion request
The system SHALL provide a `DataSubjectRequest` schema (`kind`: correction | deletion, `learnerId`, `submittedBy`,
`description`, an append-only `auditTrail` array of `{recordedBy, recordedAt, action, note}` entries — mirrors
`BehaviourIncident.followUpActions`' entry shape) with a declarative lifecycle `requested → in-review →
completed | rejected`. Creation SHALL be restricted to staff (`instructors`/`compliance-officers`). This
closes finding 15.3's documented gap ("no correction or deletion request workflow").

#### Scenario: Staff logs an incoming deletion request and tracks it to completion
- **GIVEN** a guardian has asked the school (by letter or email) to delete their child's record
- **WHEN** a compliance officer creates a `DataSubjectRequest` with `kind: deletion` for that `learnerId`
- **THEN** the request starts in `requested`, can move to `in-review` and then `completed` or `rejected`
- **AND** every transition appends an `auditTrail` entry naming who acted and when

<!-- @e2e exclude Schema/lifecycle shape verified by PrivacyGovernanceRegisterTest; the index+detail pages are declarative manifest entries (external-training-record precedent), no bespoke Vue. -->

### Requirement: A board-facing dashboard composes group, 2FA and integration-approval state
The system SHALL provide a read-only `PrivacyGovernanceController::overview()` endpoint composing: the eight
`rbac-declare-groups` group ids with live Nextcloud member counts, a best-effort two-factor-adoption count
(never fabricated — `null`/"unknown" when the registry is unavailable, not a false zero), and `DataExchangeJob`
counts by partner-approval status. Access SHALL be limited to `compliance-officers` at the navigation
layer (`visibleIf`); the endpoint itself requires only an authenticated session, mirroring
`AiProcessingDisclosureController`'s existing defence-in-depth posture.

#### Scenario: A compliance officer opens the privacy governance dashboard
- **GIVEN** the eight `rbac-declare-groups` groups exist with some members
- **WHEN** a compliance officer opens the Privacy governance dashboard
- **THEN** they see each group's member count and the count of `DataExchangeJob`s pending partner approval

#### Scenario: Two-factor adoption degrades to unknown rather than a fabricated zero
- **GIVEN** no two-factor provider is registered on the instance
- **WHEN** the dashboard loads
- **THEN** the two-factor adoption figure renders as unknown, never as `0`

<!-- @e2e exclude Controller composition verified by PHPUnit PrivacyGovernanceControllerTest (group counts, 2FA degrade-to-null, DataExchangeJob counts); the dashboard page itself is a thin declarative-data consumer with no client-side logic beyond rendering the payload. -->

### Requirement: LearnerProfile declares age-derived self-service-rights flags

`LearnerProfile` MUST carry a materialised `ageYears` (calculated via `dateDiff` from `birthDate` to `now` in
years), and two materialised booleans derived from it: `hasPartialSelfServiceRights` (true when
`ageYears >= 12`) and `hasFullSelfServiceRights` (true when `ageYears >= 16`) — finding 2.11. These are the
data-model half only; no portal UI or action gating is built by this requirement (a separate, `code`-kind
change's responsibility).

#### Scenario: A 13-year-old learner has partial but not full self-service rights

- **GIVEN** a `LearnerProfile` with `birthDate` 13 years before today
- **WHEN** the row is read
- **THEN** `ageYears` is `13`, `hasPartialSelfServiceRights` is `true`, `hasFullSelfServiceRights` is `false`

#### Scenario: A learner under 12 has neither self-service right

- **GIVEN** a `LearnerProfile` with `birthDate` 9 years before today
- **WHEN** the row is read
- **THEN** both `hasPartialSelfServiceRights` and `hasFullSelfServiceRights` are `false`

### Requirement: Six schemas declare a retention-and-destruction annotation

`LearnerProfile` and `AttendanceRecord` MUST declare `x-openregister-archival.retention.default: "P5Y"`;
`AttendanceFlag` MUST declare `"P3Y"`; `DossierNote`, `BehaviourIncident`, and `WellbeingCheckIn` MUST each
declare `"P2Y"` — each with a `category` naming the retention rationale and `action: "destroy"` (finding
2.9). OpenRegister's own `ArchivalRetentionTask` cron, destruction-list approval workflow, and
`archival.destroyed` audit-trail logging (all `status: done` in `openregister/openspec/specs/
archival-destruction-workflow`) implement the sweep, approval, and log — learniq declares only the
annotation.

#### Scenario: A LearnerProfile row's archiefactiedatum is calculated from its retention period

- **GIVEN** the `LearnerProfile` schema declares `x-openregister-archival.retention.default: "P5Y"`
- **WHEN** a new `LearnerProfile` object is created
- **THEN** its `retention.archiefactiedatum` is set to its creation date plus 5 years, per OpenRegister's own
  default archival-metadata behaviour

#### Scenario: A user-driven delete on an archival schema is rejected

<!-- @e2e exclude the 403 SCHEMA_ARCHIVAL_IMMUTABLE rejection is OpenRegister's own platform mechanism,
     already covered by archival-annotation-vocabulary's own test suite; this requirement only asserts that
     learniq's six schemas correctly declare the annotation that triggers it -->

- **GIVEN** a `DossierNote` whose schema declares `x-openregister-archival`
- **WHEN** a user attempts to delete it directly (not via the platform's destruction-list workflow)
- **THEN** OpenRegister rejects the delete with HTTP 403 `SCHEMA_ARCHIVAL_IMMUTABLE`

### Requirement: LearnerProfile records per-purpose beeldmateriaal consent
`LearnerProfile` SHALL gain `beeldmateriaalConsent` (object with nullable-boolean sub-fields `website`, `socialMedia`,
`schoolgids`, `classPhoto`, `video`, closing finding 2.8) and `beeldmateriaalConsentReviewDueAt` (nullable date, the
yearly-reminder date `PA-new-3` names). Multi-guardian resolution (`PA-new-2`: one guardian's refusal means no
consent) is a staff process — when a second guardian refuses a purpose already granted, staff update that purpose to
`false`. This is a human attestation, not a computed verdict, named explicitly rather than implied to be more.

#### Scenario: A school records per-purpose consent for a learner
- **GIVEN** a `LearnerProfile` with `beeldmateriaalConsent` unset
- **WHEN** staff set `website: true`, `socialMedia: false`, `schoolgids: true`, `classPhoto: true`, `video: false`
- **THEN** each purpose persists independently

#### Scenario: A second guardian's refusal is reflected by updating the combined record
- **GIVEN** `beeldmateriaalConsent.classPhoto: true` (one guardian consented)
- **WHEN** a second guardian refuses the same purpose and staff record it
- **THEN** `beeldmateriaalConsent.classPhoto` is set to `false` — the combined record reflects the refusal

<!-- @e2e exclude Schema-shape requirement, verified by GuardianAudienceRegisterTest; no bespoke controller — reads/writes go through OpenRegister's generic object endpoint per ADR-022, and the parent-facing read is exposed via portal-contribution's own requirement in this change. -->

## ADDED Requirements

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

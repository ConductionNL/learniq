## ADDED Requirements

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

---
kind: code
---

# Proposal: privacy-governance-surfaces

## Summary
Learniq's compliance surface (`src/views/LearniqSettings.vue` section 4) links out to OpenRegister's per-subject extract and processing log, but has no place to record the school's own AVG governance artefacts and no workflow for the two AVG rights it does not yet serve. Three gaps, evidenced against 12 competitors (kindkans, onderwijs-transparant, ldos, top-dossier, parentcom and others) and ParnasSys's own live product: (1) **15.5** — zero hits for a Privacyconvenant verwerkersovereenkomst or a privacybijsluiter anywhere in the app, while every named competitor publishes both (kindkans/onderwijs-transparant/ldos all list themselves on the Privacyconvenant deelnemers register); (2) **15.3** — the extract and processing-log links exist, but there is no correction or deletion request workflow, so two of the four AVG rights (correctie, deletion) are undocumented and untracked; (3) **P-new-6/P-new-7** — ParnasSys ships a board-level Privacybasis dashboard (2FA use, groepsautorisatie, roles, koppelingen) and a per-koppeling approval/visibility flow ("Beoordeel koppelverzoek", "Koppelingsgegevens inzien"); Learniq has neither a board-facing governance view nor any per-partner approval gate on `DataExchangeJob`, so a data-exchange partner link goes live without anyone at the school having approved it or being able to see what it pulls.

This change adds: a `Compliance` singleton schema carrying the Privacyconvenant/verwerkersovereenkomst and privacybijsluiter references; a `DataSubjectRequest` schema with a declarative lifecycle and an append-only audit trail for correction and deletion requests, surfaced as an index+detail pair and a LearniqSettings widget; four additive properties on `DataExchangeJob` plus a small guard extension gating the `run` transition on partner approval; and one read-only `PrivacyGovernanceController` composing Nextcloud group/2FA state (from the groups `rbac-declare-groups` already provisions) with `DataExchangeJob` integration counts into a board-facing dashboard page.

## Motivation
A school signing up to a Privacyconvenant-compliant SIS publishes two documents before anything else: the verwerkersovereenkomst (so a board can show its DPIA is grounded in a real processor agreement) and the privacybijsluiter (so a school can hand a parent a one-page summary of what is processed and why). `findings.md` row 15.5 confirms Learniq has neither — "zero hits for verwerkersovereenkomst or privacybijsluiter" — while ParnasSys signs its verwerkersovereenkomst through Kennisnet DV and publishes a privacybijsluiter that gets a version bump on review (`parnassys/round1/documented-column.md` row 15.5). Without a place to record these, the school's own privacy officer has nothing in Learniq to point a DPIA at.

Row 15.3 finds the AVG rights **half-served**: `LearniqSettings.vue` already opens OpenRegister's per-subject extract and the processing log (inzage, export), but "no correction or deletion request workflow" exists — a parent or learner exercising their correctie/verwijdering right today has no record in the system at all, staff-side or otherwise. ParnasSys's own equivalent is also only partial (documented-column.md row 15.3: "inzage through four exports... deletion tooling... No request workflow"), so this is a genuine market gap this change closes rather than merely a competitor-parity chase — the two-sided record (what was requested, what staff did about it) is the missing piece everywhere, including here.

P-new-6/P-new-7 are the governance-visibility half of the same problem: ParnasSys's Privacybasis dashboard (`www.parnassys.nl/parnassys/privacybasis`) gives a board member one page showing 2FA adoption, groepsautorisatie state, roles and active koppelingen; its koppelverzoek flow ("Beoordeel koppelverzoek", `parnassys.zendesk.com/hc/nl/articles/15904834387602`) requires an explicit school-side approval before a partner integration goes live, and separately lets a school see what data that partner pulls ("Koppelingsgegevens inzien"). Learniq's `rbac-declare-groups` change (18/18 done) already provisions the eight canonical Nextcloud groups this dashboard would read membership from, and `DataExchangeJob` already has one precedent for a standing approval gate (`OsoDossierReviewGuard`'s `pending-parent-review` state) — this change is the partner-level analogue of that same pattern, plus the read side ParnasSys calls "Koppelingsgegevens inzien".

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (new `Compliance` and `DataSubjectRequest` schemas, four new properties + `DataExchangePartner`-shaped fields on `DataExchangeJob`), `lib/Lifecycle/DataExchangeRunGuard.php` (partner-approval check), a new `lib/Controller/PrivacyGovernanceController.php`, `appinfo/routes.php`, `src/manifest.d/compliance.json` (index/detail/dashboard pages), a new `src/views/PrivacyGovernanceDashboard.vue`, and `src/views/LearniqSettings.vue` (a new "Privacy governance" section). No other project's files change.

## Scope

### In Scope
- `Compliance`: a flat, un-lifecycled singleton (mirrors `SovereigntyPolicy`'s precedent) carrying `privacyconvenantSigned`, `privacyconvenantSignedAt`, `verwerkersovereenkomstUrl`, `privacybijsluiterUrl`, `privacybijsluiterVersion`, `lastReviewedAt`, `lastReviewedBy`. Create/update restricted to `compliance-officers`.
- `DataSubjectRequest`: `kind` (correction | deletion), `learnerId` (the data subject), `submittedBy`, `description`, a declarative `requested → in-review → completed | rejected` lifecycle (no PHP guard — mirrors `BehaviourIncident`'s unguarded transitions), and an append-only `auditTrail` array (mirrors `BehaviourIncident.followUpActions`' entry shape: `recordedBy`, `recordedAt`, `action`, `note`). Create restricted to staff (`instructors`/`compliance-officers`) logging an incoming request; guardian/learner self-service is out of scope (see Out of Scope). Surfaced as an `index`+`detail` manifest pair (declarative, per `external-training-record`'s precedent — no bespoke Vue) plus a compact "Recent requests" list on `LearniqSettings.vue`'s new Privacy governance section, reusing the existing generic object list, not a new endpoint.
- `DataExchangeJob` gains four additive properties: `requiresPartnerApproval` (boolean, default `false`), `partnerApprovalStatus` (enum `not-required | pending | approved | rejected`, default `not-required`), `partnerApprovedBy`, `partnerApprovedAt`, and `dataSharedFields` (array of strings — the "Koppelingsgegevens inzien" answer: which fields this target pulls). `DataExchangeRunGuard::check()` gains one additional condition: a job with `requiresPartnerApproval === true` and `partnerApprovalStatus !== 'approved'` cannot reach `running` — mirrors the existing OSO gate's shape exactly, additive and backward compatible (every existing job defaults to `not-required`, so no existing target is newly blocked).
- One read-only `PrivacyGovernanceController::overview()` composing: the eight `rbac-declare-groups` group ids with live member counts (`IGroupManager`), a best-effort two-factor-adoption count (`\OCP\Authentication\TwoFactorAuth\IRegistry`, degrading to `null` if unavailable — never fabricated), and `DataExchangeJob` counts by `requiresPartnerApproval`/`partnerApprovalStatus` (active vs pending-approval "sleeping" integrations). Composes cross-cutting Nextcloud-native state no generic OR endpoint exposes — same justification `AiProcessingDisclosureController`'s own docblock already gives for its Hermiq read.
- One new manifest page, `PrivacyGovernanceDashboard` (`type: custom`, gated `visibleIf: user.primaryRole in [admin, compliance-officer]`), reading the controller above plus the `DataExchangeJob` partner-approval index via the existing generic list endpoint.

### Out of Scope
- Guardian/learner self-service submission of a correction/deletion request (a portal-side capability, and this app's own D1 restriction keeps new portal surfaces to the one allowed change, `portal-contribution-guardian-audiences`) — staff log the request on the subject's behalf for this change; self-service is a named follow-up.
- The aggregate Art. 30 register export (JSON/CSV/PDF) — already flagged as a forthcoming OpenRegister capability in `LearniqSettings.vue`'s existing copy; unrelated to this change.
- A `DataExchangePartner` registry schema — placement.md's own guidance is a property on `DataExchangeJob` plus an index, not a new schema; a partner is identified by `target` (the existing free-text job-type field).
- Changing `OsoDossierReviewGuard`'s existing OSO/SWV parent-review gate — the new partner-approval condition is additive and independent; a job can be gated by either, both, or neither.
- Any change to `rbac-declare-groups`'s own group provisioning or authorization content.

## Approach
Three additive schema changes plus one guard extension (declarative-first, ADR-031) and one small, precedented read-only controller (ADR-031's named exception for cross-cutting composition, same shape as `AiProcessingDisclosureController`). No existing schema's required fields change, no existing lifecycle transition is removed, and every new property defaults to the pre-change behaviour (a job with `requiresPartnerApproval: false` is completely unaffected). Full property lists and the guard diff are in design.md.

## New Dependencies
None. Consumes `IGroupManager` (already used by `RbacGroupCollector`/`DashboardRoleService`) and, best-effort, `\OCP\Authentication\TwoFactorAuth\IRegistry` (Nextcloud core, no new composer/npm dependency).

## Impact
- `lib/Settings/learniq_register.json` — two new schemas (`Compliance`, `DataSubjectRequest`), five new `DataExchangeJob` properties.
- `lib/Lifecycle/DataExchangeRunGuard.php` — one additional, independent gating condition.
- `lib/Controller/PrivacyGovernanceController.php` (new), `appinfo/routes.php` (one new GET route).
- `src/manifest.d/compliance.json` — new index/detail pages for `DataSubjectRequest`, one new `custom` dashboard page.
- `src/views/LearniqSettings.vue` — one new settings section; `src/views/PrivacyGovernanceDashboard.vue` (new).
- No change to any other app or to OpenRegister itself.

## Cross-Project Dependencies
None at build time. The board dashboard reads groups `rbac-declare-groups` provisions (same repo, already merged) — if that change were ever reverted the dashboard degrades to zero-member rows, not an error (see design.md).

## Risks

### Risk 1: `requiresPartnerApproval` defaulting to `false` under-protects by omission
**Severity:** Medium — **Mitigation:** this change does not retroactively set `requiresPartnerApproval: true` on any existing target — that is a judgement call for the school's privacy officer per partner, tracked as a rollout task (set it on `oso`/`uwlr`/`edu-v`/`basispoort` once those contracts exist), not inferred here. Every job stays exactly as gated as it was before this change until an operator opts a target in.

### Risk 2: Two-factor adoption count is a Nextcloud-core read with no guaranteed provider
**Severity:** Low — **Mitigation:** `PrivacyGovernanceController` wraps the `IRegistry` call and returns `null` (rendered as "unknown", never as `0`) rather than a fabricated zero when no 2FA backend is configured — same "never fabricate a verdict" posture `sovereign-ai-guarantee`'s locality classifier already established for this register.

### Risk 3: A `DataSubjectRequest` with no self-service path may undercount real requests
**Severity:** Low — **Mitigation:** named directly in Out of Scope and Open Questions; staff-logged coverage is still strictly better than the current zero, and the schema's `submittedBy`/`learnerId` split already anticipates a future self-service submitter without a schema change.

## Rollback Strategy
Revert `lib/Settings/learniq_register.json`, `lib/Lifecycle/DataExchangeRunGuard.php`, `lib/Controller/PrivacyGovernanceController.php`, `appinfo/routes.php`, `src/manifest.d/compliance.json`, `src/views/LearniqSettings.vue` and remove `src/views/PrivacyGovernanceDashboard.vue`. No existing schema is modified destructively (only additive properties), so a revert loses no data beyond any `Compliance`/`DataSubjectRequest` objects created while this change was live — the same posture every additive schema change in this register already takes.

## Open Questions
- Whether guardian/learner self-service filing of a correction/deletion request belongs in a future portal-contribution change or stays a staff-mediated workflow permanently — deferred; D1 restricts this round to the one already-scoped portal change.
- Who (which named role) is expected to set `requiresPartnerApproval: true` per target during rollout — an administrative decision for the school, not encoded here.

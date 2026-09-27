# Design: privacy-governance-surfaces

## Context
`lib/Settings/learniq_register.json` has no schema recording the school's own AVG governance artefacts
(Privacyconvenant, privacybijsluiter), no request-tracking workflow for the correction/deletion AVG rights, and
no per-partner approval gate on `DataExchangeJob`. `SovereigntyPolicy` (a flat, un-lifecycled singleton created
by `sovereign-ai-guarantee`) and `AiProcessingDisclosureController`/`LearniqAiProcessingDisclosure.vue`
(a read-only composing controller + custom manifest page) are the two closest precedents this design reuses
directly rather than inventing new shapes.

## Goals / Non-Goals
**Goals:** record Privacyconvenant/privacybijsluiter state; give staff a trackable correction/deletion request
workflow with an audit trail; give a board-level viewer group/2FA/integration-approval visibility; add a
partner-level standing approval gate to `DataExchangeJob` that does not disturb any existing job.

**Non-Goals:** guardian/learner self-service request submission (D1 restricts new portal surfaces this round);
the aggregate Art. 30 export (already tracked as a forthcoming OpenRegister capability); a `DataExchangePartner`
registry schema (placement.md specifies a property + index, not a new schema); reworking the existing
OSO/SWV parent-review gate.

## Decisions

### Decision 1: `Compliance` is a flat singleton, not a lifecycled schema
Mirrors `SovereigntyPolicy` exactly: one object per install, `create`/`update` restricted to
`compliance-officers`, no lifecycle transitions (there is nothing to transition — either the fields are
filled in or they are not). A lifecycle would force an artificial state machine onto what is really a settings
form.

### Decision 2: `DataSubjectRequest` reuses `BehaviourIncident`'s unguarded-transition + append-only-log shape
`BehaviourIncident`'s `open → in-handling → resolved` lifecycle has no PHP guard on either transition, and its
`followUpActions` append-only array (`recordedBy`, `recordedAt`, `action`) is the exact audit-trail shape this
schema needs. Reusing both means zero new PHP for the request workflow itself — the lifecycle engine and
`authorization.create` restriction (staff-only, same profile as `DossierNote`/`BehaviourIncident`) do all the
enforcement.

### Decision 3: The partner-approval gate is additive fields on `DataExchangeJob`, not a new schema
placement.md's own guidance (P-new-7 row) is "an approval-gate property on DataExchangeJob plus a small
consent-register index" — not a new registry. A partner is already identified by the existing free-text
`target` field; a second schema would duplicate that identity without adding anything the four new properties
don't already carry. The "consent-register index" is a manifest `index` page filtering `DataExchangeJob` by
`requiresPartnerApproval`, reusing the existing generic list endpoint — no new controller.

### Decision 4: The board dashboard is one small, precedented controller — not a declarative widget
Nextcloud group membership and two-factor-provider state are not OpenRegister objects, so no declarative
`x-openregister-aggregations`/`stats-block` widget can read them; a generic OR query has nothing to query.
`AiProcessingDisclosureController`'s own docblock already gives this repo's precedent and justification for
exactly this shape: "composes two cross-app registers plus derived classification into one payload — not a
single declarative OR query" (ADR-031's named exception for cross-cutting composition). `PrivacyGovernanceController`
follows the identical shape: one `#[NoAdminRequired]` read-only `index()`/`overview()` action, `IGroupManager`
for group membership, `\OCP\Authentication\TwoFactorAuth\IRegistry` (best-effort, `null` on failure — never a
fabricated zero, same posture `AiLocalityClassifier` established for an unverifiable locality verdict), and
the existing generic object endpoint (called from the Vue page directly) for the `DataExchangeJob` counts.

### Decision 5: `requiresPartnerApproval` defaults to `false` on every existing and new job
An opt-in default means this change cannot retroactively block a job that was running successfully yesterday.
Deciding which targets need standing partner approval (`oso`? `uwlr`? `basispoort`?) is an administrative
judgement call for the school's privacy officer, made after this change ships — not inferred here. This is the
same posture `rbac-declare-groups`' Risk 1 already took for its own provisioned-but-empty groups.

## Seed Data (ADR-001)
- `Compliance` — one draft object per install, all fields empty/false, so `_registers.json` seeds nothing
  false-positive: `{"privacyconvenantSigned": false, "privacyconvenantSignedAt": null, "verwerkersovereenkomstUrl": null, "privacybijsluiterUrl": null, "privacybijsluiterVersion": null, "lastReviewedAt": null, "lastReviewedBy": null}`.
- `DataSubjectRequest` — no seed objects (a request is always a real, staff-initiated event; a seeded draft
  would misrepresent an actual AVG request that never happened).
- `DataExchangeJob` — no new seed objects; the four new properties apply additively to whatever seed/live jobs
  already exist, all defaulting to `not-required`/`false`.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| `Compliance` create/update RBAC | Declarative (`authorization`) | Simple staff-only gate, no conditional logic |
| `DataSubjectRequest` lifecycle | Declarative (`x-openregister-lifecycle`, unguarded) | Mirrors `BehaviourIncident`; no cross-object check needed at any transition |
| `DataSubjectRequest` audit trail | Declarative (append-only array property) | Mirrors `BehaviourIncident.followUpActions`; no PHP needed to accumulate entries — the client posts the next array state, same as every other append-only log in this register |
| `DataExchangeJob` partner-approval gate | Imperative (guard extension) | ADR-031 exception: a lifecycle `requires` guard is exactly the declarative extension point for this; `DataExchangeRunGuard` already exists and already reads `object.target` — extending its one `check()` method is the smallest possible diff, not a new service class |
| Board dashboard composition | Imperative (controller) | ADR-031 exception: cross-cutting composition of Nextcloud-native state (groups, 2FA) that is not an OpenRegister object — identical justification to `AiProcessingDisclosureController` |

## Risks / Trade-offs
- [Risk] A staff member could log a `DataSubjectRequest` against the wrong `learnerId` → [Mitigation] the audit
  trail records who created and who progressed the request; a correction is itself a new audit-trail entry, not
  a silent edit — same shape every append-only record in this register already uses.
- [Risk] The 2FA registry call could throw on some Nextcloud configurations → [Mitigation] wrapped in try/catch,
  logged as a warning, returns `null` — the controller-level equivalent of `AiLocalityClassifier`'s "never
  fabricate a verdict" rule.

## Migration Plan
No Nextcloud migration class — this is a declarative OpenRegister schema-register update (JSON) plus one PHP
guard extension and one new read-only controller/route. On the next configuration import, the two new schemas
and the five new `DataExchangeJob` properties become available; no existing object needs a backfill (every new
property has an additive default). Rollback: revert the register JSON, `DataExchangeRunGuard.php`, delete
`PrivacyGovernanceController.php` and its route.

## Open Questions
- Whether a future change should let the data subject (guardian/learner) submit their own correction/deletion
  request via the portal — deferred per D1 (see proposal's Open Questions).

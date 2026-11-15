---
kind: config
depends_on: []
---

# Proposal: statutory-field-completeness

## Summary

Closes four statutory/legal MUST-tier gaps: pupil self-service rights at 12+/16+ (finding 2.11, an
age-derived flag on `LearnerProfile`); referentieniveau (1F/1S/2F) tracking per attempt on `AssessmentResult`
(finding 6.8); thuiszitter and langdurig-relatief-verzuim flag classification on `AttendanceFlag`
(finding 4.9); and a retention-and-destruction declaration (finding 2.9) on six schemas — five of the ten that
`avg-verwerkingsregister` already declares as processing activities, using OpenRegister's own
`x-openregister-archival` dialect (confirmed present and "status: done" in `openregister/openspec/specs/
archival-annotation-vocabulary` and `retention-management`) rather than a learniq-built background job.

## Motivation

- **2.11**: "no: nearest `lib/Portal/PortalContributionProvider.php` student audience (no age rule, no rights
  or consent actions)." `PortalContributionProvider.php` is a PHP consumer and out of this `config`-kind
  change's scope; this change adds the age-derived data the portal (a future, separate `code` change) would
  read to gate self-service actions — the same "landing spot, no caller yet" posture already used for
  `Assignment.plagiarismProvider`/`Submission.plagiarismScore` in the sibling `assessment-completeness`
  change.
- **6.8**: "no: zero hits for referentieniveau." `AssessmentResult` is the per-attempt, append-only record
  findings.md itself points at ("per pupil over time" is satisfied by querying multiple `AssessmentResult`
  rows for one learner — no new mechanism needed for the "over time" dimension).
- **4.9**: "no: zero hits for thuiszitter or langdurig verzuim." `AttendanceFlag` already fires on any
  `AttendanceThreshold` crossing but carries no classification of *which kind* of statutory concern the flag
  represents.
- **2.9**: "no: nearest ... `retentionReference` ('not declared' on all 10 processing schemas)." Read
  `openregister/openspec/specs/` per this lane's brief before building anything learniq-side: OpenRegister
  already ships a complete, platform-owned retention/destruction dialect (`x-openregister-archival` +
  `ArchivalRetentionTask` cron sweep + `archival.destroyed` audit-trail logging, per
  `archival-annotation-vocabulary/spec.md` and `archival-destruction-workflow/spec.md`, both `status: done`).
  Building a learniq-side background job would duplicate a platform capability that already exists — this
  change only declares the annotation.

## Affected Projects

- [x] Project: `learniq` — `LearnerProfile`, `AssessmentResult`, `AttendanceFlag` schema additions;
  `x-openregister-archival` declared on six processing-activity schemas.

## Scope

### In Scope

- `LearnerProfile`: `ageYears` (materialised, `dateDiff(birthDate, now, years)`), `hasPartialSelfServiceRights`
  (materialised boolean, `ageYears >= 12`), `hasFullSelfServiceRights` (materialised boolean,
  `ageYears >= 16`) — the data a future portal change would read to gate self-service rights/consent actions.
- `AssessmentResult`: `referentieniveau` (nullable enum: `1F`, `1S`, `2F`, `2S`, `3F`, `3S` — the full
  standard Dutch referentieniveau taxonomy, a superset of the finding's named `1F`/`1S`/`2F`).
- `AttendanceFlag`: `flagKind` (enum: `signal-verzuim`, `langdurig-relatief-verzuim`, `thuiszitter`; default
  `signal-verzuim`) — classifies which statutory concern a flag represents. SWV notification reuses the
  existing generic `dataExchangeJobId` field; no new field needed for that half of the finding.
- `x-openregister-archival` declared on `LearnerProfile` (P5Y), `AttendanceRecord` (P5Y), `DossierNote`
  (P2Y), `BehaviourIncident` (P2Y), `WellbeingCheckIn` (P2Y) — five of the ten `avg-verwerkingsregister`
  processing-activity schemas, chosen as the ones most directly tied to this change's own statutory findings
  (leerplicht/verzuim/dossier) — plus `AttendanceFlag` (P3Y), which is not itself one of the ten
  processing-activity carriers but is the schema finding 4.9 names directly. The remaining five of the ten
  (`Assessment`, `Attestation`, `Credential`, `DataExchangeJob`, `AiFeature`, see Out of Scope) are deferred
  to a follow-up sweep to keep this change at its briefed `M` size.

### Out of Scope

- Declaring `x-openregister-archival` on the remaining five processing-activity schemas (`Assessment`,
  `Attestation`, `Credential`, `DataExchangeJob`, `AiFeature`) — deferred to a follow-up (documented above),
  not silently dropped.
- Any change to `PortalContributionProvider.php` or a new portal-facing self-service action UI — the
  age-derived flags are the data-model half; wiring them into an actual gated action is `code`-kind work for
  a separate change.
- Enforcing the retention periods' *correctness* against a specific selectielijst gemeenten/onderwijs
  category — the 2/3/5-year mapping below is this change's best-effort, documented reasoning (see design.md),
  not a legally-reviewed determination; `avg-verwerkingsregister`'s own precedent already treats such content
  as a privacy-officer-reviewable draft, and this change follows the same posture.
- A learniq-built background job for destruction — confirmed unnecessary; OpenRegister's `ArchivalRetentionTask`
  cron already sweeps and destroys per Motivation above.

## Approach

Every change is a declarative schema property, materialised calculation, or `x-openregister-archival`
annotation. No PHP, no Vue, no manifest change.

## Capabilities

### Modified Capabilities

- `avg-verwerkingsregister` — six processing-activity schemas gain a real `x-openregister-archival`
  declaration, closing the "not declared" retention gap the capability's own schema-level comment already
  named.
- `attendance` — `AttendanceFlag` gains a `flagKind` classification.
- `assessment` — `AssessmentResult` gains `referentieniveau`.
- `school-structure`/`avg-verwerkingsregister` — `LearnerProfile` gains age-derived self-service-rights flags.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json` — `LearnerProfile` (+3 properties), `AssessmentResult` (+1 property),
  `AttendanceFlag` (+1 property), six schemas gain `x-openregister-archival`.

## Cross-Project Dependencies

None — `x-openregister-archival` is an existing, already-shipped OpenRegister capability; this change only
declares the annotation on learniq's own schemas.

## Risks

### Risk 1: The `dateDiff`/`now` calculation syntax was verified against OpenRegister's own openspec
documentation, not against a live instance

**Severity:** Medium — **Mitigation:** the exact shape used here
(`{"dateDiff": {"from": {"prop": "@self.birthDate"}, "to": "now", "unit": "years"}}`) is copied from a
worked example in `openregister/openspec/specs/computed-fields/spec.md`'s own scenario text (line 611: `{
"dateDiff": { "from": "now", "to": { "prop": "@self.dueDate" }, "unit": "days" } }`), not invented; the
`unit: "years"` value was not separately confirmed against the validator's supported-unit list (the spec
names `calculation-dateDiff-invalid-unit` as an error code but does not enumerate every valid unit in the
text this change's research reached). If `"years"` is unsupported, the fix is isolated to `ageYears`'
expression — `hasPartialSelfServiceRights`/`hasFullSelfServiceRights` would need the same fix, but no other
part of this change depends on it.

### Risk 2: The 2/3/5-year retention mapping is this change's own best-effort reasoning, not a legally verified determination

**Severity:** Medium — **Mitigation:** documented explicitly (Out of Scope, design.md) as a draft mapping for
a privacy officer to confirm, mirroring `avg-verwerkingsregister`'s own "seeds arrive as drafts" posture for
exactly this reason; OpenRegister's own destruction workflow only acts on what a human has approved via its
destruction-list approval flow (`archival-destruction-workflow/spec.md`), so a wrong duration here is
correctable before anything is ever destroyed, not a silent data-loss risk.

## Rollback Strategy

Every change is additive (new nullable/defaulted/materialised properties, a new annotation). Revert the
commit(s); `x-openregister-archival` blocks user-driven deletes but the cron sweep only destroys rows past
`archiefactiedatum` — reverting before any row reaches that date has zero destructive effect.

## Open Questions

- Confirm `dateDiff`'s `unit: "years"` is a supported value against a live OpenRegister instance (Risk 1).
- A privacy officer should confirm the 2/3/5-year mapping (Risk 2) before these six schemas' seeds are
  activated in a real deployment.

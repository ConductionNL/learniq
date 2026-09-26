---
kind: config
---

# Proposal: care-and-support-index

## Summary
Adds a care-team lens over `LearningPlans` (pupils in support, upcoming evaluations), a new
`ObservationInstrument` schema for structured kleuter (groep 1-2) leerlijn observations, a new
`Trajectory`/`TrajectoryStatusUpdate` pair for bovenschoolse-voorziening placements (OPDC, rebound),
and the OPP residual on `LearningPlan`: a materialised six-week activation clock and
uitstroombestemming bandwidth tracking.

## Motivation
Round-1 competitor research (`compare/change-plan.md`, "Report cards, care and support" table)
carries this change against five findings:

- **8.11** Care team overview (`compare/findings.md`): kindkans, onderwijs-transparant and sera all
  surface "pupils in support, upcoming evaluations" as a single lens; learniq's own
  `LearningPlans` index has `nextReviewAt` (and the schema already carries a materialised
  `nextReviewDue` calculation, `learning-plan` spec's existing "Persist LearningPlan domain
  objects" requirement) but no dedicated care-team view groups it.
- **6.9** Observation instruments for kleuters (`compare/findings.md`): IEP's own documented scale
  ("toont geen interesse, is aan het ontdekken, kan het met hulp, kan het zelfstandig",
  `lvs-report/round1/documented-columns.md` row 7.2) and ParnasSys's "Leer- en
  ontwikkelingslijnen jonge kind" package both structure groep 1-2 observation per leerlijn
  (taal, rekenen, sociaal-emotioneel, motoriek, spel, leren-leren per IEP's own citation). learniq
  has no schema for this at all (nearest is the generic `CompetencyFrameworkDetail`, not
  kleuter-specific).
- **L-new-10** Bovenschoolse voorziening (OPDC, rebound) trajectory (`compare/proposed-rows.md`):
  sera documents a `referral → preparation → scheduling → running → stopped` traject-status
  lifecycle. learniq's `SupportRequestDetail` stops at the SWV/TLV request; no placement lifecycle
  exists beyond it.
- **8.3 residual** OPP six-week clock and uitstroombestemming (`compare/findings.md`,
  `placement.md` row 8.3): ParnasSys and Somtoday both document a statutory "create the OPP within
  six weeks" clock; learniq's `LearningPlan` already has the signature guard and evaluation cycle
  (shipped) but no deadline tracking.
- **L-new-4** OPP uitstroombestemming with bandwidths (`compare/findings.md`): ESIS documents
  "uitstroombestemming" and "Geplande groei" (a vaardigheidsscore bandwidth the plan tracks growth
  against) as OPP step 5. Additive to `LearningPlan`.

## Affected Projects
- [x] Project: `learniq` — `LearningPlan` additive fields + calculation + notification; new
  `ObservationInstrument`, `Trajectory`, `TrajectoryStatusUpdate` schemas; care-team-relevant
  columns on the existing `LearningPlans` index plus a query-preset nav deep-link (no duplicate
  index page, per ADR-097 Decision 5).

## Scope

### In Scope
- `LearningPlan` gains: `sixWeekDeadline` (nullable date, set by the coordinator at plan creation
  for `kind: opp`), a materialised `isOverdueForActivation` calculation (`lifecycle == draft` AND
  `sixWeekDeadline` has passed), a `sixWeekDeadlineApproaching` declared notification (mirroring
  `TlvApplication.tlvExpiringSoon`'s idiom), `uitstroombestemming` (nullable string), and
  `outflowBandwidth` (nullable object: `lowerVaardigheidsscore`/`upperVaardigheidsscore`/`scale`)
  plus `trackedGrowth[]` (dated vaardigheidsscore entries a coordinator logs against the band).
- New `ObservationInstrument` schema: `learnerId`, `academicYear`, `period`, `entries[]` (each:
  `leerlijn` enum `taal`/`rekenen`/`sociaal-emotioneel`/`motoriek`/`spel`/`leren-leren`,
  `observedAt`, `level` enum matching IEP's documented four-point kleuter scale, `observedBy`,
  nullable `note`), `tenant_id`. PO-scoped (groep 1-2); no lifecycle beyond `active`/`archived`
  (an observation instrument is a standing per-learner record, not a workflow).
- New `Trajectory` schema (bovenschoolse voorziening placement): `learnerId`, nullable
  `supportRequestId` ($ref `SupportRequest`, extending its existing pattern rather than
  duplicating it), `voorzieningType`, `referredAt`, lifecycle `referral → preparation → scheduled →
  running → stopped`. New `TrajectoryStatusUpdate` (append-only, mirrors
  `LearningPlanEvaluation`/`DeliberationRecord`'s append-only precedent): `trajectoryId`,
  `recordedAt`, `recordedBy`, `note`.
- `LearningPlans` (config, manifest-only) gains `columns` for learner, kind, coordinator,
  lifecycle, and `nextReviewAt` (using the already-shipped `nextReviewDue` calculation), and a new
  `CareTeamOverviewMenu` nav entry deep-links to that same page with a `lifecycle: "active"`
  `menu[].query` preset (the manifest v2 schema's own documented role-lens mechanism) — the
  "pupils in support, upcoming evaluations" lens finding 8.11 asks for, without a second `type:
  "index"` page over `learning-plan` (ADR-097 Decision 5, enforced by gate-68
  `duplicate-index-pages`).

### Out of Scope
- ROD export of the OPP's begin/end dates (part of 8.3's full statutory picture) — that is a
  `DataExchangeJob`-adapter-level change (D3's job-type-owns-mapping pattern) with no existing
  contract to extend yet; a future learniq change, not this one.
- A dedicated care-team role/permission model beyond the existing RBAC roles already declared on
  `LearningPlan` — `care-and-support-index` only adds a lens, it does not change who may read what.
- VO/MBO-specific observation instruments — 6.9's evidence (IEP, ParnasSys) is PO/kleuter-specific;
  a VO equivalent is not evidenced in this round's corpus.
- The `slugify-ref-relation-resolver` nextcloud-vue fix that currently blanks any detail page
  resolving a PascalCase `$ref` (including `LearningPlanDetail`'s related panel) — tracked and
  owned by another lane; not worked around here.

## Approach
Every addition is declarative: new schema properties, two new schemas following existing sibling
patterns (`ObservationInstrument` mirrors `DossierNote`'s per-learner standing-record shape;
`Trajectory`/`TrajectoryStatusUpdate` mirrors `SupportRequest`'s lifecycle plus
`LearningPlanEvaluation`'s append-only review-log shape), a materialised calculation and a declared
notification (both proven idioms already used by `TlvApplication.tlvExpiringSoon`), and one new
manifest index page. No new PHP class.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: `LearningPlan` additive fields/calculation/notification;
  new `ObservationInstrument`, `Trajectory`, `TrajectoryStatusUpdate` schemas.
- `lib/Settings/learniq_mock_register.json`: seed objects for the three new/extended shapes.
- `src/manifest.d/learning.json`: `LearningPlans` columns + `CareTeamOverviewMenu` query-preset deep-link; `ObservationInstrument`,
  `Trajectory`, `TrajectoryStatusUpdate` index+detail pages.
- `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new): schema/calculation shape
  assertions.

## Cross-Project Dependencies
None — every addition is self-contained within learniq's own register and manifest.

## Risks

### Risk 1: sixWeekDeadline has no automatic trigger date
**Severity:** Low — **Mitigation:** the deadline is coordinator-set at plan creation (matching
`TlvApplication.validUntil`'s own coordinator-set precedent), not auto-derived from a
`SupportRequest` decision date — deriving it automatically would need a cross-schema listener this
round's evidence does not require (ParnasSys/Somtoday document the six-week rule as a coordinator
obligation to track, not an automated calculation input).

## Rollback Strategy
Every property/schema addition here is additive and nullable/independently-lifecycled; reverting
the register patch removes them with no impact on existing `LearningPlan`/`SupportRequest` rows.

## Open Questions
None — corpus evidence is specific enough to proceed; scope boundaries above record judgment calls
made under headless operation.

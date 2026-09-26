# Design: care-and-support-index

## Architecture Overview
Four independent, additive pieces, all declarative:

1. `LearningPlan` gains a materialised deadline calculation + notification (mirrors
   `TlvApplication.tlvExpiringSoon` exactly) and a nullable outflow-bandwidth property cluster.
2. `ObservationInstrument` — a new, standalone per-learner standing record (mirrors `DossierNote`'s
   shape: no complex lifecycle, just `active`/`archived`).
3. `Trajectory` + `TrajectoryStatusUpdate` — a new lifecycle'd placement record plus an append-only
   status log (mirrors `SupportRequest`'s lifecycle shape and `LearningPlanEvaluation`'s
   append-only review-log shape respectively).
4. A care-team lens: `LearningPlans` gains coordinator/next-review columns, and a
   `CareTeamOverviewMenu` nav entry deep-links to it with a `lifecycle: active` `menu[].query`
   preset — no second index page (ADR-097 Decision 5, gate-68 `duplicate-index-pages`).

No new PHP class in any of the four — every behaviour here is expressible in
`x-openregister-{lifecycle,calculations,notifications}`, matching this register's own strong
precedent (`TlvApplication`, `AccessibilityStatement`, `Enrolment`'s `isOverdue` idiom).

## API Design
No new HTTP endpoint. Every new/extended schema is served by OpenRegister's generic object API.

## Database Changes
See `migration.md` — additive OpenRegister schema changes only.

## Nextcloud Integration
- Controllers: none added.
- Services: none added.
- Mappers/Entities: none — OpenRegister owns storage.
- Events/Hooks: none added — `isOverdueForActivation`/`sixWeekDeadlineApproaching` are OR-native
  declarations (materialised calculation + scheduled-trigger notification), not PHP listeners.

## Security Considerations
`ObservationInstrument` and `Trajectory`/`TrajectoryStatusUpdate` carry no `x-property-rbac`
narrower than this register's existing role model requires — they follow the same default
authenticated-staff-role posture as `DossierNote`/`SupportRequest` (both already declare tighter
RBAC where the corpus evidence calls for it; this round's evidence names no confidentiality
requirement beyond what those siblings already enforce). The care-team lens reads only
`learning-plan`, which already declares its own RBAC — no new read surface, only new columns and a
query-preset deep-link over the existing page.

## NL Design System
The three new index+detail pairs (`Trajectory`, `TrajectoryStatusUpdate`, `ObservationInstrument`)
are declarative `src/manifest.json` pages using the same generic widgets (`data`, `related`,
`object-list`) every other page in this register already uses; the care-team lens adds no new page.

## File Structure
```
lib/
  Settings/
    learniq_register.json        (MODIFIED — LearningPlan additive fields/calc/notification; new ObservationInstrument, Trajectory, TrajectoryStatusUpdate schemas)
    learniq_mock_register.json   (MODIFIED — seed objects for the new/extended shapes)
src/
  manifest.d/
    learning.json                 (MODIFIED — LearningPlans columns + CareTeamOverviewMenu query-preset deep-link; ObservationInstrument/Trajectory/TrajectoryStatusUpdate index+detail pages)
tests/
  Unit/
    Settings/CareAndSupportIndexRegisterTest.php (NEW)
```

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path chosen | Rationale |
|---|---|---|
| `LearningPlan.isOverdueForActivation` | Declarative (`x-openregister-calculations`) | Identical shape to the already-shipped `TlvApplication.tlvExpiringSoon` — a materialised boolean over `dateDiff`/`now`. No PHP needed. |
| `LearningPlan.sixWeekDeadlineApproaching` | Declarative (`x-openregister-notifications`, `scheduled` trigger) | Mirrors `TlvApplication`'s own expiry-notification idiom exactly. |
| `Trajectory` lifecycle | Declarative (`x-openregister-lifecycle`) | A plain state machine with no cross-schema side effect — no guard class needed, mirrors `SupportRequest`'s own lifecycle shape. |
| `TrajectoryStatusUpdate` append-only log | Declarative (`appendOnly: true`) | Mirrors `LearningPlanEvaluation`/`DeliberationRecord` exactly. |
| Care-team lens (columns + query preset) | Declarative (manifest `config.columns` + `menu[].query`) | New columns plus the documented deep-link mechanism over an existing page — no new query logic, no duplicate page. |

## Seed Data
### Schema: `observation-instrument`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `observationinstrument-groep-1-leerling-1` | `observationinstrument-groep-2-leerling-2` | `observationinstrument-groep-1-leerling-3` |
| learnerId | learner-001 | learner-002 | learner-003 |
| academicYear | 2025-2026 | 2025-2026 | 2025-2026 |
| period | Blok 2 | Blok 2 | Blok 1 |
| entries[] | taal/is-aan-het-ontdekken, rekenen/kan-het-met-hulp | sociaal-emotioneel/kan-het-zelfstandig | motoriek/kan-het-met-hulp, spel/kan-het-zelfstandig |
| lifecycle | active | active | active |

### Schema: `trajectory`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `trajectory-opdc-referral-1` | `trajectory-rebound-running-2` | `trajectory-opdc-stopped-3` |
| learnerId | learner-004 | learner-005 | learner-006 |
| voorzieningType | OPDC | Rebound | OPDC |
| referredAt | 2026-01-15 | 2025-11-01 | 2025-09-01 |
| lifecycle | referral | running | stopped |

**Related items per object:** none.

Seed also updates: one `LearningPlan` mock row (`kind: opp`) gains `sixWeekDeadline`,
`uitstroombestemming`, and `outflowBandwidth` to demonstrate the residual OPP fields in the seeded
environment.

## Trade-offs
Considered deriving `sixWeekDeadline` automatically from a linked `SupportRequest`'s decision date.
Rejected: no `LearningPlan` field currently links back to the originating `SupportRequest` (the
relationship is evidence-only, via `goals[].evidenceRefs`), and no evidence this round shows a
statutory requirement to compute the deadline rather than let the coordinator set it — ParnasSys's
own documentation frames the six-week rule as an obligation the coordinator tracks, not an
automated calculation input (see proposal.md Risk 1).

Considered a second `type: "index"` page (`CareTeamOverview`) with a hardcoded `lifecycle: {in:
[...]}` `config.filter`. Rejected on two independent grounds: (1) this manifest's `filter` key is
`additionalProperties: true` with no formal multi-value grammar demonstrated anywhere in it (every
precedent is an exact-match route-param join, e.g. `Submission`'s `assignmentId:
"@route.assignmentId"`) — an untested operator risks a silent no-op filter that looks correct in
the JSON and does nothing at runtime; (2) `gate-68 duplicate-index-pages` and ADR-097 Decision 5
name this exact shape — "a second index over an already-indexed schema" — as an anti-pattern with a
documented alternative already shipped in the manifest v2 schema: `menu[].query` ("deep-links a nav
entry to a pre-filtered index page"). Adding `columns` to the existing `LearningPlans` page plus a
`CareTeamOverviewMenu` entry carrying `query: {lifecycle: "active"}` uses only mechanisms the schema
itself documents, and confirmed clean against gate-68 (0 findings) after the change.

## Open Questions
None outstanding.

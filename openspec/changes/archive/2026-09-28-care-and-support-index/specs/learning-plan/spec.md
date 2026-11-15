# learning-plan Specification

## ADDED Requirements

### Requirement: LearningPlan declares a materialised six-week activation clock

`LearningPlan` MUST carry a nullable `sixWeekDeadline` (date), coordinator-set at plan creation, and
a materialised `isOverdueForActivation` boolean calculation (`true` when `lifecycle` is `draft` AND
`sixWeekDeadline` is set AND has passed `@now`), mirroring `TlvApplication.tlvExpiringSoon`'s
declared-calculation idiom (a `dateDiff`/`now` expression, not a PHP TimedJob). A
`sixWeekDeadlineApproaching` declared notification (`scheduled` trigger, filtered to
`lifecycle: draft` with `sixWeekDeadline` within 7 days) MUST reach the plan's `coordinatorId`, the
same shape `TlvApplication`'s own expiry notification already uses.

#### Scenario: isOverdueForActivation is true once the deadline has passed on a still-draft plan

<!-- @e2e exclude Pure OpenRegister calculation expression; no scholiq DOM surface for the calculation itself, verified by reasoning over the register JSON mirroring TlvApplicationRegisterTest's established pattern for tlvExpiringSoon. -->

- **GIVEN** a `LearningPlan` in `draft` with `sixWeekDeadline` set to a date in the past
- **WHEN** `isOverdueForActivation` is evaluated
- **THEN** it is `true`

#### Scenario: isOverdueForActivation is false once the plan has activated, regardless of the deadline

<!-- @e2e exclude Pure calculation expression; same scope as above. -->

- **GIVEN** a `LearningPlan` with `sixWeekDeadline` in the past that has since transitioned to
  `active`
- **WHEN** `isOverdueForActivation` is evaluated
- **THEN** it is `false`

### Requirement: LearningPlan declares OPP uitstroombestemming and a tracked growth bandwidth

`LearningPlan` MUST carry a nullable `uitstroombestemming` (string) and a nullable
`outflowBandwidth` object (`lowerVaardigheidsscore`, `upperVaardigheidsscore`, `scale`), plus a
`trackedGrowth[]` array of dated vaardigheidsscore entries a coordinator logs against that band
(each: `recordedAt`, `vaardigheidsscore`), extending the existing OPP (`kind: opp`) shape
additively — both fields are independent of `sixWeekDeadline`/`isOverdueForActivation` above.

#### Scenario: A tracked growth entry persists against the declared bandwidth

<!-- @e2e exclude Pure schema/array persistence; no scholiq DOM surface beyond the generic manifest form's array widget, covered by PHPUnit CareAndSupportIndexRegisterTest. -->

- **GIVEN** a `LearningPlan` (`kind: opp`) with `outflowBandwidth` set to a lower/upper
  vaardigheidsscore pair
- **WHEN** a coordinator appends a `trackedGrowth[]` entry
- **THEN** the entry persists with its `recordedAt` and `vaardigheidsscore`, independent of the
  plan's `lifecycle` state

### Requirement: ObservationInstrument records structured kleuter leerlijn observations

The system MUST persist `ObservationInstrument` as an OpenRegister object: `learnerId`,
`academicYear`, `period`, `entries[]` (each: `leerlijn` — one of `taal`, `rekenen`,
`sociaal-emotioneel`, `motoriek`, `spel`, `leren-leren` — `observedAt`, `level` — one of the four
IEP-documented kleuter-scale values `toont-geen-interesse`, `is-aan-het-ontdekken`,
`kan-het-met-hulp`, `kan-het-zelfstandig` — `observedBy`, and a nullable `note`), `tenant_id`, and a
`lifecycle` of `active`/`archived`.

#### Scenario: An ObservationInstrument persists one entry per leerlijn per observation moment

<!-- @e2e exclude Pure OpenRegister schema persistence; no scholiq DOM surface for schema registration itself, covered by CareAndSupportIndexRegisterTest mirroring the established `*RegisterTest` convention. -->

- **GIVEN** the `care-and-support-index` schemas are registered
- **WHEN** an `ObservationInstrument` is created for a learner with one entry per each of the six
  leerlijnen, each carrying a distinct `level` value
- **THEN** it persists as a valid OpenRegister object with all six entries intact

### Requirement: Trajectory tracks a bovenschoolse-voorziening placement lifecycle, with an append-only status log

The system MUST persist `Trajectory` (`learnerId`, nullable `supportRequestId` — `$ref
SupportRequest`, extending its existing pattern rather than duplicating it — `voorzieningType`,
`referredAt`, `tenant_id`) with `x-openregister-lifecycle` `referral → preparation → scheduled →
running → stopped`, and `TrajectoryStatusUpdate` (`trajectoryId`, `recordedAt`, `recordedBy`,
`note`) as `appendOnly: true`, mirroring `LearningPlanEvaluation`/`DeliberationRecord`'s
append-only review-log precedent (ADR-008).

#### Scenario: A Trajectory moves through its full lifecycle with a status update at each stage

<!-- @e2e exclude Pure OpenRegister lifecycle/appendOnly persistence; no scholiq DOM surface for the transitions themselves, covered by CareAndSupportIndexRegisterTest asserting the declared transition table and TrajectoryStatusUpdate's appendOnly flag. -->

- **GIVEN** a `Trajectory` created in `referral`
- **WHEN** it transitions `referral → preparation → scheduled → running → stopped`, with one
  `TrajectoryStatusUpdate` recorded at each transition
- **THEN** every transition succeeds in declared order and every `TrajectoryStatusUpdate` remains
  immutable (`appendOnly: true`)

### Requirement: A care-team lens surfaces pupils in support without a duplicate index page

`LearningPlans` MUST declare `columns` for the learner, `kind`, `coordinatorId`, `lifecycle`, and
`nextReviewAt` (reusing the already-shipped `nextReviewDue` materialised calculation, per the
existing `learning-plan` spec's own "Persist LearningPlan domain objects" requirement). A
`CareTeamOverviewMenu` nav entry MUST deep-link to the same `LearningPlans` route with a
`lifecycle: "active"` `menu[].query` preset — the manifest v2 schema's own documented mechanism
("deep-links a nav entry to a pre-filtered index page") — rather than declaring a second `type:
"index"` page over `learning-plan`, per ADR-097 Decision 5 ("a second index over an already-indexed
schema is a role lens, not a page") and its enforcing ratchet, gate-68 `duplicate-index-pages`.

#### Scenario: LearningPlans carries the care-team-relevant columns, with no duplicate index page

<!-- @e2e exclude Declarative manifest column/menu-query addition, no new component behaviour to exercise; verified by reasoning over the built effective manifest (build_effective_manifest.js) and gate-68 duplicate-index-pages, mirroring how report-card-templates verified its own new manifest pages. -->

- **GIVEN** the manifest is built
- **WHEN** `LearningPlans` is inspected
- **THEN** its `columns` include the learner, `kind`, `coordinatorId`, `lifecycle`, and
  `nextReviewAt`
- **AND** `CareTeamOverviewMenu` routes to `LearningPlans` with `query: {lifecycle: "active"}`, and
  no second `type: "index"` page exists over the `learning-plan` schema (gate-68 reports 0
  findings)

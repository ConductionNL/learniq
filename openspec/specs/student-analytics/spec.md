# student-analytics Specification

## Purpose
TBD - created by archiving change learning-progress-and-analytics. Update Purpose after archive.

## Requirements

### Requirement: Persist EngagementScore domain objects in OpenRegister

The system MUST persist `EngagementScore` as an OpenRegister object — one row per `(learnerId, courseId)` —
carrying `activityCount` (a declarative `x-openregister-aggregate-refs` count of matching `XapiStatement`
rows), `timeOnTaskMinutes` and `score` (PHP-computed by `OCA\Scholiq\Analytics\EngagementScoreEvaluator`,
because summing nested `XapiStatement.result` duration data has no declarative `sum` operator in this
register's calculation DSL), `lastActivityAt` (PHP-set) and `recencyDays` (a genuinely declarative
`materialise: true` `dateDiff` from `lastActivityAt` to `@now`, the same idiom `Enrolment.daysRemaining`
already uses). `x-property-rbac.read` MUST scope to the learner themself, admin, and the course's teacher(s).

#### Scenario: EngagementScore objects persist and recompute from xAPI activity
<!-- @e2e exclude Pure OpenRegister schema/calculation registration + backend recompute, verified by PHPUnit (EngagementScoreEvaluatorTest) and schema-validation tests; no scholiq DOM surface to drive an xAPI event firing server-side. -->

- **GIVEN** the `student-analytics` schemas are registered in OpenRegister
- **WHEN** an `XapiStatement` is received for a learner and course with no existing `EngagementScore`
- **THEN** an `EngagementScore` row is created for `(learnerId, courseId)`
- **AND** `activityCount` reads `1`, `lastActivityAt` matches the statement's `timestamp`, and `recencyDays`
  materialises via `dateDiff` from `lastActivityAt` to `@now`

#### Scenario: Time-on-task accumulates across statements
<!-- @e2e exclude Backend arithmetic covered by EngagementScoreEvaluatorTest (multiple statements with result.duration extensions summing correctly; a statement with no duration extension contributing 0, not an error). -->

- **GIVEN** a learner has 3 prior `XapiStatement`s for a course, each carrying a `result` duration extension
- **WHEN** a 4th statement with a duration extension is received
- **THEN** `EngagementScore.timeOnTaskMinutes` reflects the sum of all 4 durations

### Requirement: At-risk detection beyond BSA is a deterministic, rule-based threshold — not AI/ML

The system MUST support `EngagementRiskThreshold` (config: `name`, `kind`, `scope`, `cohortId` nullable,
`metric` [`engagement-score-below` | `recency-days-above`], `limit`, `onAtRisk`, `lifecycle: draft → active →
archived`, mirroring `AttendanceThreshold`) and `EngagementRiskFlag` (not `appendOnly`, `lifecycle: open →
in-handling → resolved`, mirroring `AttendanceFlag`/`BsaProgressFlag` — human-in-the-loop, never auto-acting
against the learner). Unlike `BsaTrajectory`, this threshold MUST apply to any `Course`/`Cohort` — it MUST
NOT be gated on `Course.ectsCredits` or on an HE/MBO `kind`. Detection MUST be a plain arithmetic/threshold
comparison over `EngagementScore` fields, evaluated by a single event-driven handler
(`OCA\Scholiq\Listener\EngagementSignalHandler`) off the same `XapiStatement` event `EngagementScoreEvaluator`
reacts to — NOT a `TimedJob` (ADR-022), and NOT an AI/ML inference call of any kind. Any future
predictive/AI-assisted at-risk extension MUST be routed through Hermiq's `agentaifeature` register behind the
ADR-005 gate, in a separate change — it MUST NOT be built as local Scholiq logic.

#### Scenario: Falling below the engagement threshold raises a flag, generalised beyond BSA
<!-- @e2e exclude Calculation + trigger behaviour is backend/lifecycle logic verified by PHPUnit (EngagementSignalHandlerTest); no DOM surface for a declared threshold crossing firing server-side. -->

- **GIVEN** an `EngagementRiskThreshold` (`metric: engagement-score-below`, `limit: 30`) active for a
  `po`/`vo` `Cohort` with no `ectsCredits`-bearing courses (i.e. a learner population `BsaTrajectory` can
  never flag)
- **AND** a learner in that cohort whose `EngagementScore.score` is `20`
- **WHEN** the score recomputes on a new `XapiStatement`
- **THEN** an `EngagementRiskFlag` (`open`) is created for that learner
- **AND** no AI/ML service is called anywhere in the detection path

#### Scenario: A resolved flag does not block re-flagging on a later relapse
<!-- @e2e exclude Backend idempotency + re-trigger, covered by EngagementSignalHandlerTest. -->

- **GIVEN** a learner's prior `EngagementRiskFlag` is `resolved`
- **WHEN** their `EngagementScore.score` crosses back below the threshold on new activity
- **THEN** a new `EngagementRiskFlag` (`open`) is created — the idempotency guard only suppresses duplicates
  while a flag is already `open`/`in-handling`

### Requirement: Cohort/group test-score trend renders as a heat map over existing data

The system MUST render a cohort/group test-score trend heat map (`GroupTrendHeatmap.vue`, one named custom
view) over the **existing** `GradeEntry.cohortId`/`GradeEntry.gradedAt`/`GradeEntry.value` data via
OpenRegister's existing list/aggregate API — it MUST NOT introduce a new persisted schema for the trend
itself (no rollup/snapshot object; the source data already exists and OR already serves aggregation over it).
This closes the `dashboard` capability spec's Acceptance Criteria claim of a "skill-area heat map" that has
no implementation anywhere in `src/views/**` at HEAD. The view MUST be scoped to teacher/admin roles and MUST
NOT nest a second `CnDashboardPage` inside an existing dashboard route (the `dashboard` spec's single-
`CnDashboardPage`-per-route rule).

#### Scenario: Teacher views the cohort trend heat map
- **GIVEN** a teacher with a `Cohort` that has `GradeEntry`s spanning 3 grading periods
- **WHEN** they open the Group trend heat map view
- **THEN** a grid of cohort × period cells renders, each colour-banded by that period's average `GradeEntry.
  value`, sourced entirely from existing `GradeEntry` data via the OpenRegister object API
- **@e2e** tests/e2e/spec-coverage/student-analytics.spec.ts

#### Scenario: Heat map is not a nested dashboard
- **GIVEN** the `GroupTrendHeatmap` component tree
- **WHEN** it is rendered as its own manifest page (not a dashboard widget slot)
- **THEN** it does not render a `CnDashboardPage` inside another dashboard route
<!-- @e2e exclude Structural/anti-pattern assertion — enforced by the hydra dashboard-antipattern gate, mirroring the `Learning dashboard is not a nested dashboard` precedent in this codebase's own ai-surface spec; not a positive DOM behaviour distinct from the render scenario above. -->

### Requirement: A planner forecasts next year's learners per programme year and subject

A user in `team-leads` or `compliance-officers` MUST be able to keep forecast scenarios for a target school year, with a progression rate per programme and programme year (up, repeat, leave, adding up to one) and an expected intake, and MUST be able to compute from them, the current cohorts, the placed applications and the approved subject choices the number of learners per programme year and per subject next year. Figures derived from a share of choices made so far MUST be marked as estimated. Computing MUST NOT change any cohort, enrolment or choice.

#### Scenario: A deputy head forecasts havo 4

- **GIVEN** 118 learners in havo 3 this year and a scenario with rates 0.88 up, 0.07 repeat and 0.05 leave for havo 3
- **WHEN** the deputy head opens Reports, "Enrolment forecast", picks the scenario and computes
- **THEN** havo 4 next year shows 104 learners from havo 3 plus the repeaters of havo 4

#### Scenario: Rates that do not add up are refused

<!-- @e2e exclude Service validation; covered by EnrolmentForecastServiceTest::testRatesMustAddUpToOne. -->

- **GIVEN** a scenario whose rates for vwo 5 add up to 1.1
- **WHEN** it is computed
- **THEN** the computation is refused with a reason naming vwo 5

### Requirement: The forecast says how many groups are needed

The computed forecast MUST show per programme year and per subject the number of groups needed at the scenario's target group size, rounded up.

#### Scenario: Groups for an elective subject

- **GIVEN** a forecast of 61 learners taking "Economie" in havo 4 and a target group size of 28
- **WHEN** the planner reads the subject table
- **THEN** "Economie" in havo 4 needs 3 groups

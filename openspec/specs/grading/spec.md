---
slug: grading
title: Grading — Grade Entries, Scales, Final Grades, Soft-Publish
status: in-progress
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [pta-se-vo, eindcijfer-he, ects-conversion, pass-fail-certification]
replaces: [grading-pta]
openspec_changes:
  - learner-lookup-and-learnerrefs-fixes
---

# Grading

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, declarative calculation triggers, and notification dispatch rules — no `#### Scenario:` headings exist in this spec.

## Purpose

Component grades have to roll up into a final grade, and the roll-up rule belongs to the governing plan, not the gradebook. Dutch VO does this with the **PTA**: each `kolom` has a `weegfactor`, kolommen group by `periode`, and the weighted average across periods is the **SE-gemiddelde** which (with the CE) gives the **eindcijfer**. An HE module does the same with `deeltoetsen → eindcijfer`. A certification track does it with `all-must-pass`. Magister/SOMtoday own the Dutch VO workflow today but draw systemic UX backlash (instant per-grade pings, no concept state, opaque impact). This spec generalises it: a `GradeEntry` is one mark on one component for one learner; a `FinalGrade` is computed from a learner's GradeEntries using the `CurriculumPlan`'s declared `formula` and `component weights` (from `school-structure`); soft-publish lets a teacher review the cohort distribution before any parent/learner notification fires; the learner sees each grade's weight and its impact on the running average.

## What

- **GradeScale** — a named scale: numeric `1.0–10.0` (NL), letter `A–F`, ECTS `A–F`, pass/fail, percentage, custom band sets. Carries the pass threshold and rounding rule.
- **GradeEntry** — one mark: learnerId, the CurriculumPlan `componentId` it scores, the source object (`assignmentSubmissionId` | `assessmentResultId` | `participationSessionId` | manual), `value` (on the component's GradeScale), `weight` (defaults from the CurriculumPlan component but overridable per entry), `period`, `lifecycle` (concept → published → revised), grader, gradedAt, comment.
- **FinalGrade** — a *calculated* schema: per learner per Course/Programme, recomputed (via `x-openregister-calculations` + the cross-schema-aggregation feature) whenever a GradeEntry for that learner publishes — applying the CurriculumPlan's `formula` (`weighted-average` | `last-attempt` | `best-of-n` | `all-must-pass`) over the published GradeEntries, producing the value, the pass/fail verdict, and a `breakdown` (per-period averages, per-component contributions).
- **Soft-publish** — GradeEntries start `concept`; a teacher batch-publishes a cohort's entries in one action *after* previewing the distribution. Only `published` entries notify (per the learner's / parent's notification-preference: instant ping vs daily digest, with the 18+-learner controlling their own per AVG-Onderwijs).
- **Impact view** — when a learner opens a new GradeEntry, the detail shows its weight, the points it contributed, and the resulting period-average and final-grade delta.

## User Stories

- As a teacher, I want the weegfactor per kolom to come from the PTA (CurriculumPlan) so the periode- and SE-gemiddelde compute automatically — and I want to override a single entry's weight when the situation warrants.
- As a teacher, I want to enter a cohort's grades in concept, preview the distribution, and publish them in one batch so parents and learners aren't pinged per deeltoetsje.
- As a parent, I want to choose instant push or a daily digest of new grades; as an 18+ learner, I want that choice to be mine, not my parent's.
- As a learner, I want each new grade shown with its weight, points contributed, and the resulting change to my period average and final grade.
- As a certification coordinator, I want an `all-must-pass` formula so a learner is only "passed" once every required component is at or above its threshold.

## Acceptance Criteria

- GIVEN a CurriculumPlan component with weight 3, WHEN a GradeEntry publishes for it, THEN the learner's FinalGrade recomputes with that entry counting 3× in the weighted average; a per-entry `weight` override takes precedence over the component default.
- GIVEN a teacher saves a cohort's GradeEntries as `concept` and opens the distribution preview, THEN no parent/learner notification has fired; on batch publish, exactly one notification per recipient fires according to their preference.
- GIVEN a parent set "daily digest", WHEN several grades publish during the day, THEN one summary notification fires at the configured time.
- GIVEN a learner opens a published GradeEntry, THEN the detail shows weight, points contributed, and the period-average and final-grade deltas.
- GIVEN a CurriculumPlan with `formula: all-must-pass` and one component below threshold, THEN the FinalGrade `passed` is false regardless of the average.

## Requirements

### Requirement: Persist grading domain objects in OpenRegister

The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects.
`GradeEntry` has `x-openregister-lifecycle` (concept → published → revised) and
`x-openregister-notifications` keyed so a re-publish/backfill doesn't double-notify. `FinalGrade`
is computed via `x-openregister-calculations` + cross-schema aggregation over the learner's
published `GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights.
`GradeEntry.sourceKind` MUST include `portfolio` alongside the existing `assignment-submission`,
`assessment-result`, `participation`, `manual`, `exemption`, and `lti-ags` values, so a mark
originating from a graded `course-bound` `Portfolio` carries an honest, traceable origin rather
than being recorded as `manual`. When `sourceKind = portfolio`, `GradeEntry` MUST carry
`portfolioId` (the originating `Portfolio`, `$ref: Portfolio`), nullable and set only for this
`sourceKind` — the same additive, per-`sourceKind` reference-field shape `submissionId` /
`assessmentResultId` / `exemptionCaseId` / `fraudCaseId` / `ltiToolPlacementId` already use.

#### Scenario: Grading objects persisted in OpenRegister

- **GIVEN** the grading domain schemas are registered
- **WHEN** a `GradeEntry` is published for a learner
- **THEN** `GradeScale`, `GradeEntry`, and `FinalGrade` are stored as OpenRegister objects and the
  `FinalGrade` is computed via `x-openregister-calculations` over the learner's published entries

#### Scenario: A graded course-bound portfolio creates a traceable concept GradeEntry

<!-- @e2e exclude Cross-schema event-to-object-write bridge is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest, mirroring GradeRollupHandlerTest::testAssessmentResultGradedCreatesConceptGradeEntry's equivalent coverage. -->

- **GIVEN** a `course-bound` `Portfolio` with `gradeValue` set, in `submitted` state
- **WHEN** the portfolio transitions to `graded`
- **THEN** a `GradeEntry` is created with `sourceKind: 'portfolio'`, `lifecycle: 'concept'`, and
  `portfolioId` set to the originating `Portfolio`
- **AND** it is NOT recorded with `sourceKind: 'manual'`

#### Scenario: Re-processing the graded transition does not create a duplicate GradeEntry

<!-- @e2e exclude Idempotency guard is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest::testNoDuplicateWhenGradeEntryIdAlreadySet, mirroring GradeRollupHandler::handleAssessmentResultGraded()'s existing `gradeEntryId already set` skip. -->

- **GIVEN** a `Portfolio` whose `gradeEntryId` is already set from a prior `graded` transition
- **WHEN** the `graded` transition is processed again for the same portfolio
- **THEN** no second `GradeEntry` is created

### Requirement: Notification dispatch honours per-parent/per-18+-learner preference

Notification dispatch MUST honour per-parent / per-18+-learner preference (instant vs daily digest),
backed by a `NotificationPreference` schema or the existing OR notification-preference mechanism
(whichever OR exposes). Dispatch additionally MUST NOT occur before the triggering `GradeEntry`'s resolved
`visibleFrom`, regardless of the recipient's instant-vs-digest preference — the preference controls
batching/timing of an already-eligible notification, not whether the visibility window has opened yet.

#### Scenario: Dispatch respects recipient preference

- **GIVEN** a parent has set "daily digest" and an 18+ learner has set "instant"
- **WHEN** grades publish for that learner and their `visibleFrom` has already passed
- **THEN** the parent receives one batched digest notification and the learner receives an instant
  notification, each according to their own preference

#### Scenario: Night publish defers notification to the resolved visibleFrom

- **GIVEN** a teacher batch-publishes a cohort's `GradeEntry`s at 23:40 under a `CurriculumPlan` whose
  `gradeVisibilityPolicy.mode` is `nextSchoolDay` at `10:00`
- **WHEN** the publish transition completes
- **THEN** no `nc-notification` is delivered to any learner or parent that night
- **AND** the learner's and parents' notifications (per their own instant/digest preference) are eligible
  to fire only once `visibleFrom` (the next school day, 10:00) has passed

#### Scenario: Teacher overrides the default visibility window

- **GIVEN** a teacher batch-publishes a cohort's `GradeEntry`s and explicitly sets `visibleFrom` to
  "right now" as part of the publish action
- **WHEN** the publish transition completes
- **THEN** the explicit override is used instead of the `CurriculumPlan.gradeVisibilityPolicy` default
- **AND** dispatch proceeds immediately (subject to each recipient's instant/digest preference)

### Requirement: Roll-up is a declared calculation, not a TimedJob
The roll-up MUST NOT be a PHP TimedJob — it MUST be a declared calculation that re-fires on `GradeEntry`
publish (the `calculatedChange` trigger feature). The only PHP exception allowed: a stateless
`GradeFormulaEvaluator` invoked by the calculation engine if a formula can't be expressed in JSON-logic
(ADR-031 "calculation engine above schema metadata"). `GradeFormulaEvaluator` is extended, not replaced, to
treat `sourceKind: exemption` entries as follows: excluded from `weightedAverage()`'s `weightedSum`/
`totalWeight` accumulation (both overall and per-period) so a `null` value never corrupts the average; in
`evaluatePassed()`'s `all-must-pass` branch, a component whose best entry is `sourceKind: exemption` is
treated as satisfying that component's `passRules` threshold without a numeric comparison.
`FinalGrade.breakdown.components[componentId]` gains an `exempt: true` marker on any component satisfied
this way, so the roll-up UI can show why the component counts.

#### Scenario: Roll-up re-fires on publish without a TimedJob
- **GIVEN** a learner's `FinalGrade` is derived from a declared calculation
- **WHEN** a `GradeEntry` for that learner transitions to `published`
- **THEN** the `FinalGrade` recomputes via the `calculatedChange` trigger with no PHP TimedJob involved

#### Scenario: An exemption entry does not corrupt the weighted average
- **GIVEN** a `CurriculumPlan` with `formula: weighted-average` and two components, one satisfied by a
  numeric `GradeEntry` (value 7.0, weight 2) and one satisfied by a `sourceKind: exemption` entry (value
  null, weight 3)
- **WHEN** `GradeFormulaEvaluator` computes the `FinalGrade`
- **THEN** the exemption entry contributes nothing to `weightedSum` or `totalWeight`
- **AND** the resulting `value` equals the numeric entry's own value (7.0), not a value dragged down by
  treating the null exemption value as zero
- **AND** `breakdown.components[componentId]` for the exempted component carries `exempt: true`

#### Scenario: An exemption satisfies an all-must-pass component without a numeric check
- **GIVEN** a `CurriculumPlan` with `formula: all-must-pass` and a `passRules` threshold for a component that
  is satisfied only by a `sourceKind: exemption` `GradeEntry`
- **WHEN** `GradeFormulaEvaluator.evaluatePassed()` runs
- **THEN** that component's `passRules` threshold is treated as satisfied without comparing the (null)
  exemption value against it, and `passed` is not forced to `false` solely because of that component

### Requirement: Frontend is declarative with named custom views
Frontend MUST be declarative: `src/manifest.json` pages for GradeEntry/FinalGrade index+detail per cohort; a custom `GradebookView` (the cohort grid with concept→publish — genuine UI) and `GradeImpactDetail` Vue component. No PHP CRUD controllers.

#### Scenario: Pages and custom views are manifest-declared
- **GIVEN** the grading frontend is configured
- **WHEN** the app renders GradeEntry and FinalGrade screens
- **THEN** index/detail pages come from `src/manifest.json` and only `GradebookView` and `GradeImpactDetail` exist as custom Vue components, with no PHP CRUD controllers

### Requirement: `GradeEntry`/`FinalGrade` read access includes the exam-board role
`GradeEntry.x-property-rbac.read` and `FinalGrade.x-property-rbac.read` MUST gain an `examboard` role
alongside the existing `admin`/self-match (`learnerId`) `anyOf`, so an exam-board member can read the specific
grade their `ExemptionCase` or `FraudCase` concerns — without widening read access to every grade in the
school.

#### Scenario: An exam-board member reads a grade tied to their case
- **GIVEN** a user in the `examboard` NC group, and a `GradeEntry` linked via `exemptionCaseId` or
  `fraudCaseId` to a case they are handling
- **WHEN** they request that `GradeEntry` or its `FinalGrade`
- **THEN** `x-property-rbac.read`'s `examboard` role clause grants access

### Requirement: `GradeEntry.invalidate` is a guarded terminal transition
`GradeEntry` MUST gain a new terminal lifecycle state `invalidated` and a transition `invalidate` (`concept →
invalidated`), guarded by `FraudCaseInvalidationGuard`, which permits the transition only when the entry's
`fraudCaseId` refers to a `FraudCase` that is `decided` with `verdict: fraud-proven`. The transition is fired
by `FraudCaseDecisionHandler` (an `exam-board` capability listener), never invoked directly by a user action.

#### Scenario: Invalidate is blocked without a fraud-proven decision
- **GIVEN** a `GradeEntry` in `concept` with `fraudCaseId` set to a `FraudCase` that is not yet `decided`, or
  `decided` with `verdict: unfounded`
- **WHEN** `invalidate` is attempted
- **THEN** `FraudCaseInvalidationGuard` blocks it

#### Scenario: Invalidate succeeds once the linked case is decided fraud-proven
- **GIVEN** a `GradeEntry` in `concept` with `fraudCaseId` set to a `FraudCase` that is `decided` with
  `verdict: fraud-proven`
- **WHEN** `FraudCaseDecisionHandler` drives `invalidate`
- **THEN** the `GradeEntry` transitions to `invalidated`, a terminal state from which no further transition is
  possible

### Requirement: GradeScale declares DLE and leerrendement as scale kinds, for later LVS data

`GradeScale.kind` MUST gain two additional enum values, `dle` (didactische leeftijd) and
`leerrendement`, alongside the existing `numeric`/`letter`/`ects`/`pass-fail`/`percentage`/`band`
values — a vocabulary declaration only, with no calculation attached, so the not-yet-built
`lvs-import-contract` change (tier B) has a scale kind to attach imported LVS results to rather
than inventing one alongside its own, larger scope.

#### Scenario: A GradeScale can be declared with the new kind values

<!-- @e2e exclude Pure OpenRegister enum declaration; no scholiq DOM surface for schema registration itself, covered by PHPUnit GradeScaleDleLeerrendementRegisterTest mirroring the established `*RegisterTest` convention. -->

- **GIVEN** the `grading` schemas are registered
- **WHEN** a `GradeScale` is created with `kind: "dle"` and another with `kind: "leerrendement"`
- **THEN** both persist as valid OpenRegister objects

### Requirement: A teacher previews and batch-publishes a cohort's concept grades

`CohortGradebookView` MUST offer, under the grid, a publish panel scoped to one plan component or to
all components. For the marks in scope that are `concept` or `published` it MUST show the count,
average, lowest and highest mark and a histogram with a text count per band, and, when the plan's
`GradeScale` has a `passThreshold`, how many pass. It MUST offer "Publish N marks", where N is the
number of `concept` entries in scope with a numeric value, and MUST ask for confirmation first. On
confirmation it MUST fire the existing `publish` transition for each of those entries, continue past
a refused entry, and then report how many were published and which were not, with the reason.

#### Scenario: The preview shows the spread before anything is published

<!-- @e2e exclude Distribution logic covered by node test tests/unit-js/gradebookPublish.test.mjs; lanes do not run against the shared instance. -->

- **GIVEN** a component with concept marks 4.5, 6.0, 7.5 and 8.0 on a scale with `passThreshold` 5.5
- **WHEN** the teacher picks that component
- **THEN** the panel shows 4 marks, average 6.5, lowest 4.5, highest 8.0 and 3 passing, and nothing
  is published yet

#### Scenario: Batch publish publishes every concept mark in scope

<!-- @e2e exclude Node test tests/unit-js/gradebookPublish.test.mjs covers which entries are publishable; the transition is the existing GradeEntry publish. -->

- **GIVEN** a component with 3 concept marks, 1 published mark and 1 concept entry without a value
- **WHEN** the teacher confirms "Publish 3 marks"
- **THEN** the `publish` transition fires for exactly those 3 entries

#### Scenario: A refused entry does not stop the batch

<!-- @e2e exclude Node test tests/unit-js/gradebookPublish.test.mjs (publishReport). -->

- **GIVEN** a batch of 3 where the server refuses one entry because its report period is locked
- **WHEN** the batch runs
- **THEN** the other 2 are published and the panel names the refused learner with the reason

### Requirement: Every GradeEntry carries a server-stamped learnerRef

`GradeEntryLearnerRefStamp` MUST set `GradeEntry.learnerRef` on every create and update of a
`grade-entry` object, derived from `learnerId` through `LearnerRefResolver`: the UUID of the
`LearnerProfile` whose `ncUserId` equals `learnerId`, preferring a profile whose `mergedInto` is
empty. A `learnerRef` sent by the client MUST be ignored, so no caller can point a grade at another
learner's portal subject. When no profile matches, `learnerRef` MUST be null, so the grade stays
out of the portal (fail-closed). The stamp MUST NOT block the write. On update, when the lookup
fails with an error, the stored `learnerRef` MUST be kept.

#### Scenario: A grade created without learnerRef gets it stamped

<!-- @e2e exclude Server-side write listener with no DOM surface; covered by PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** a LearnerProfile `lp-1` with `ncUserId: "pupil-1"`
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"` and no `learnerRef`
- **THEN** the stored entry carries `learnerRef: "lp-1"`

#### Scenario: A forged learnerRef is replaced by the derived one

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** LearnerProfiles `lp-1` (`ncUserId: "pupil-1"`) and `lp-2` (`ncUserId: "pupil-2"`)
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"` and `learnerRef: "lp-2"`
- **THEN** the stored entry carries `learnerRef: "lp-1"`

#### Scenario: A learner without a profile stays out of the portal

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** no LearnerProfile with `ncUserId: "pupil-9"`
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-9"` and a client-sent `learnerRef`
- **THEN** the write succeeds and the stored entry carries `learnerRef: null`

#### Scenario: The survivor of a merge wins over the merged-away profile

<!-- @e2e exclude PHPUnit LearnerRefResolverTest. -->

- **GIVEN** LearnerProfiles `lp-old` (`ncUserId: "pupil-1"`, `mergedInto: "lp-new"`) and `lp-new`
  (`ncUserId: "pupil-1"`, `mergedInto` empty)
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"`
- **THEN** the stored entry carries `learnerRef: "lp-new"`

#### Scenario: A failed lookup on update keeps the stored learnerRef

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** a stored `GradeEntry` with `learnerId: "pupil-1"` and `learnerRef: "lp-1"`
- **WHEN** it is updated while the LearnerProfile lookup throws
- **THEN** the write succeeds and the entry keeps `learnerRef: "lp-1"`

### Requirement: Existing GradeEntries are back-filled once

The `BackfillGradeEntryLearnerRef` repair step MUST stamp `learnerRef` on every existing
`grade-entry` object that has a `learnerId` and no `learnerRef`, using the same resolver. It MUST
skip rows that already carry a `learnerRef` and rows whose learner has no profile, and it MUST be
safe to run again: a second run writes nothing new.

#### Scenario: The backfill stamps unstamped rows and skips the rest

<!-- @e2e exclude Repair step with no DOM surface; covered by PHPUnit BackfillGradeEntryLearnerRefTest. -->

- **GIVEN** three GradeEntries: one for `pupil-1` without `learnerRef`, one for `pupil-1` with
  `learnerRef: "lp-1"`, and one for `pupil-9` who has no profile
- **WHEN** the repair step runs
- **THEN** only the first entry is saved, with `learnerRef: "lp-1"`, and a second run saves nothing

### Requirement: Parent grade notifications find the learner's profile on ncUserId

Publishing a GradeEntry MUST notify every parent in the learner's `LearnerProfile.parentIds`, where the profile is found on `ncUserId`. The lookup MUST NOT depend on the publisher's own read access to LearnerProfile, and MUST NOT pick up another learner's profile.

#### Scenario: The learner's own parents are notified

- **GIVEN** pupil `leerling-001` whose profile lists `ouder-001` and `ouder-002`, and another pupil whose profile lists `ouder-099`
- **WHEN** a grade for `leerling-001` is published
- **THEN** grade notifications are written for `ouder-001` and `ouder-002` only

## Standards

Schema.org `Grade`; NL VO PTA/SE convention as a `CurriculumPlan` profile + `GradeScale` 1.0–10.0; ECTS A–F; AVG-Onderwijs (parent vs 18+-learner notification rights); Open Onderwijs API `results` endpoint shape for HE result publication (follow-up, out of scope here).

## Data Model

All in OpenRegister. New: `GradeScale`, `GradeEntry`, `FinalGrade`, (`NotificationPreference` if OR doesn't already provide one). Consumes: `CurriculumPlan` (`school-structure`), `Submission` (`assignments`), `AssessmentResult` (`assessment`), `Session` (participation), `LtiToolPlacement` (`course-management` — cross-referenced as the source of `GradeEntry.ltiToolPlacementId` for `sourceKind: lti-ags`). One ADR-031 PHP exception: `GradeFormulaEvaluator` (only if a formula exceeds JSON-logic). See `docs/ARCHITECTURE.md`.

## Out of Scope

- Centraal-Examen (CE) result import and the CE+SE→eindcijfer combination — that's a DUO/`data-exchange` concern.
- Cross-school cohort analytics and benchmarking (handled by launchpad via runtime GraphQL).
- Transcript / diploma-supplement document generation (the `certification` spec issues the credential; DocuDesk does templating).
- AI-assisted grading (would be an `AiFeature` registration; not in scope).

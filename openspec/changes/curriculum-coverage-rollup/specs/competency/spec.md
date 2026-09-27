# competency Specification

## ADDED Requirements

### Requirement: CurriculumCoverage is a derived, read-only coverage object per framework, year and subject

The system MUST persist `CurriculumCoverage` (slug `curriculum-coverage`) as a read-only
(`x-openregister.readOnly: true`), lifecycle-free OpenRegister object that holds no learner data. Each row MUST carry
`frameworkId` (required, `$ref: CompetencyFramework`), `year` (nullable string: a year label, or `null` for the
all-years row), `subjectScope` (required enum `all | subject | none`), `subjectId` (nullable `$ref: Course`, set only
when `subjectScope` is `subject`), `goalCount`, `plannedCount`, `assessedCount`, `plannedNotAssessedCount`,
`uncoveredCount` (integers), `plannedPercent` and `assessedPercent` (numbers, one decimal, 0 when `goalCount` is 0),
`plannedByDepth` and `assessedByDepth` (arrays of `{depth, count}`), `uncoveredIds` and `plannedNotAssessedIds` (arrays
of `$ref: Competency`), `goals` (the per-goal detail: `competencyId`, `planned`, `assessed`, `plannedDepth`,
`assessedDepth`, `plannedRefs`, `assessedRefs`), `lastRecomputedAt` and `tenant_id`. Its `authorization` MUST let
teaching and coordinating staff read it (`instructors`, `team-leads`, `coordinators`, `administration-managers`,
`compliance-officers`) and keep the cascade writers for create and update, mirroring `ItemStatistics`. The schema
description MUST say coverage is a planning signal and not evidence of learning.

#### Scenario: A teacher reads coverage, a learner does not

<!-- @e2e exclude Register authorization shape; no DOM surface in this change (the view ships in curriculum-coverage-matrix-view). Covered by CurriculumCoverageRegisterTest::testCoverageSchemaIsReadOnlyStaffOnlyAndLifecycleFree. -->

- **GIVEN** the `CurriculumCoverage` schema
- **WHEN** its authorization block is read
- **THEN** `instructors`, `team-leads`, `coordinators`, `administration-managers` and `compliance-officers` may read it
- **AND** no `learners` or `guardians` entry and no self-match entry exist, and the schema has no lifecycle

### Requirement: Coverage counts leaf goals, planned and assessed separately

For one framework, the system MUST count only leaf goals (a non-archived `Competency` with no non-archived child in
the framework). A goal MUST count as planned when at least one non-retired `Lesson` or non-archived `Course` aligns
to it, and as assessed when at least one non-archived `Assignment` or `Assessment` does, using
`CompetencyAlignmentNormaliser::effectiveAlignments()` (so a row with only `competencyIds` counts, with depth
`null`). Per goal the deepest depth per kind MUST be kept by the framework's level order, where a real level beats
`null` and a depth the framework no longer declares reads as `null`. A goal nothing aligns to MUST appear in
`uncoveredIds`; a goal planned but not assessed MUST appear in `plannedNotAssessedIds`.

#### Scenario: A goal taught but never tested shows as a gap in assessment

<!-- @e2e exclude Pure calculation; covered by CurriculumCoverageCalculatorTest::testCountsPlannedAndAssessedSeparately. -->

- **GIVEN** a framework with three leaf goals A, B and C
- **AND** a lesson aligned to A and B, and an assignment aligned to A
- **WHEN** coverage is computed
- **THEN** `plannedCount` is 2, `assessedCount` is 1, `plannedNotAssessedIds` is `[B]` and `uncoveredIds` is `[C]`

#### Scenario: A domain node and archived rows do not count

<!-- @e2e exclude Pure calculation; covered by CurriculumCoverageCalculatorTest::testOnlyLeafGoalsCount and ::testArchivedGoalsAndRetiredReferencesAreLeftOut. -->

- **GIVEN** a domain goal with two leaf kerndoelen, one archived leaf, and a retired lesson aligned to a leaf
- **WHEN** coverage is computed
- **THEN** `goalCount` is 2 (the domain and the archived leaf are left out)
- **AND** the retired lesson does not make any goal planned

#### Scenario: The deepest depth wins

<!-- @e2e exclude Pure calculation; covered by CurriculumCoverageCalculatorTest::testDeepestDepthWinsAndStaleDepthReadsAsNone. -->

- **GIVEN** a framework with levels `introduce`, `practise`, `master` and one leaf goal
- **AND** one lesson aligns to it at `introduce`, another at `master`, a third without depth
- **WHEN** coverage is computed
- **THEN** the goal's `plannedDepth` is `master`, `plannedRefs` is 3, and `plannedByDepth` is `[{depth: "master", count: 1}]`

### Requirement: Coverage is bucketed by year and subject, with totals

The system MUST resolve each leaf goal's effective years and subject with the `competency-year-scope` read rule
(own non-empty value, else the nearest ancestor's), comparing year labels after trimming and lower-casing. It MUST
write, per framework: one row per distinct year label plus an all-years row (`year: null`), crossed with one row per
distinct subject (`subjectScope: subject`), an all-subjects row (`subjectScope: all`) and, when any goal has no
subject, a no-subject row (`subjectScope: none`). A goal with no years MUST count in every year row. A bucket with no
goals MUST NOT be written, except the all-years, all-subjects total row, which MUST always exist.

#### Scenario: Goals set per groep land in the right columns

<!-- @e2e exclude Pure calculation; covered by CurriculumCoverageCalculatorTest::testBucketsCoverEveryYearAndSubjectPlusTotals and ::testYearsAndSubjectsInheritFromTheNearestAncestor. -->

- **GIVEN** a domain with `applicableYears: ["groep 5"]` and subject rekenen, holding two leaf goals without values of
  their own, and a third leaf goal with `applicableYears: []` and no subject anywhere up its tree
- **WHEN** coverage is computed
- **THEN** the `groep 5` / rekenen row counts the two inherited goals
- **AND** the `groep 5` / all-subjects row counts all three goals, because the third applies to every year
- **AND** a `groep 5` / no-subject row counts the third goal, and the total row counts all three

#### Scenario: An empty framework still has a total row

<!-- @e2e exclude Pure calculation; covered by CurriculumCoverageCalculatorTest::testEmptyFrameworkStillGetsATotalRow. -->

- **GIVEN** a framework with no goals
- **WHEN** coverage is computed
- **THEN** exactly one row exists: all years, all subjects, `goalCount` 0 and both percentages 0

### Requirement: Coverage is recomputed on save for the touched frameworks only, never by a TimedJob

`CurriculumCoverageRollupHandler` MUST listen to OpenRegister's `ObjectCreatedEvent`, `ObjectUpdatedEvent` and
`ObjectDeletedEvent`, narrowed through `ObjectEventSubscription` to the `learniq` register and the schemas `lesson`,
`course`, `assignment`, `exam`, `competency` and `competency-framework`. For a lesson, course, assignment or
assessment it MUST recompute the frameworks of every goal in the old and new `competencyIds` and alignments; for a
goal, its old and new framework; for a framework, itself. `CurriculumCoverageRollup` MUST save only rows whose
numbers changed, delete the framework's rows whose bucket no longer exists, delete all of a framework's rows when
the framework is gone, and never throw into the save that triggered it. No `TimedJob` (ADR-022).

#### Scenario: Aligning a lesson updates the coverage of that framework only

<!-- @e2e exclude Backend listener; covered by CurriculumCoverageRollupHandlerTest::testLessonSaveRecomputesTheFrameworksOfOldAndNewGoals. -->

- **GIVEN** a lesson whose alignment moves from a goal in framework F1 to a goal in framework F2
- **WHEN** the update is saved
- **THEN** coverage recomputes for F1 and F2 and for no other framework

#### Scenario: A recompute writes only what changed and removes vanished buckets

<!-- @e2e exclude Backend service; covered by CurriculumCoverageRollupTest::testRecomputeUpsertsOnlyChangedRows and ::testRecomputeDeletesVanishedBuckets. -->

- **GIVEN** stored coverage rows for a framework, one of them for a year label no goal uses any more
- **WHEN** the framework is recomputed and one other row's numbers are unchanged
- **THEN** the unchanged row is not saved, the changed rows are saved, and the orphaned year row is deleted

#### Scenario: A failing recompute never breaks the save

<!-- @e2e exclude Backend listener; covered by CurriculumCoverageRollupHandlerTest::testFailureNeverBreaksTheSave. -->

- **GIVEN** the rollup throws while recomputing
- **WHEN** the listener handles a lesson save
- **THEN** the exception is logged and not rethrown

### Requirement: An occ command fills coverage for existing data

The system MUST register `occ learniq:curriculum-coverage:recompute` with an optional `--framework=<uuid>` option. Without
the option it MUST recompute every framework; with it, that framework only. It MUST report how many frameworks it
recomputed and exit 0, or exit 1 with a message when the named framework does not exist.

#### Scenario: An administrator fills coverage after an upgrade

<!-- @e2e exclude occ command, no DOM surface; covered by RecomputeCurriculumCoverageTest::testRecomputesEveryFramework and ::testRecomputesOneFramework. -->

- **GIVEN** three frameworks with goals and aligned lessons saved before this change
- **WHEN** an administrator runs `occ learniq:curriculum-coverage:recompute`
- **THEN** each framework gets its coverage rows and the command reports 3 frameworks

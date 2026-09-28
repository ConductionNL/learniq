# competency Specification

## Purpose
TBD - created by archiving change competency-framework. Update Purpose after archive.

## Requirements

### Requirement: CompetencyFramework carries a named source authority and its own proficiency scale

The system MUST persist `CompetencyFramework` as an OpenRegister object with `sourceAuthority` (required
enum `sbb-kwalificatiedossier | slo-kerndoelen | slo-eindtermen | esco | school-defined | other`), an
optional `sourceRef` (the external dossier code, kerndoelenset id, or ESCO taxonomy URI) and `edition`
(the framework's own version/jaarversie, distinct from the schema's own OpenRegister `version`), `level`
(reusing the `po|vo|mbo|hbo|wo|corporate` enum already used by `Course.level`/`Programme.level`), and a
required, non-empty `proficiencyLevels[]` array (`{levelId, label, order, minPercent}`, mirroring
`Rubric.criteria[].levels[]`'s nested-array shape) declaring the ordered scale every `Competency` under
this framework is measured against. `CompetencyFramework` MUST carry `x-openregister-lifecycle`
(`draft → published → archived`, mirroring `Course`).

#### Scenario: A school defines a two-level proficiency scale for a kwalificatiedossier framework

<!-- @e2e exclude Pure OpenRegister schema shape; no scholiq DOM surface beyond the declarative manifest pages already covered by other specs' UI conventions — covered by the register-validation test referenced in tasks.md. -->

- **GIVEN** a curriculum designer authoring a new `CompetencyFramework`
- **WHEN** they set `sourceAuthority: sbb-kwalificatiedossier`, `sourceRef` to the dossier code, and
  `proficiencyLevels` to `[{levelId: "nog-niet-competent", label: "...", order: 0}, {levelId: "competent",
  label: "...", order: 1}]`
- **THEN** the framework persists with its scale, and any `Competency` created under it can resolve that
  scale via `frameworkId`

#### Scenario: A framework cannot be created without at least one proficiency level

<!-- @e2e exclude Required-array validation is a pure JSON Schema constraint; covered by the register-validation test referenced in tasks.md. -->

- **GIVEN** a `CompetencyFramework` payload with `proficiencyLevels: []`
- **WHEN** it is submitted
- **THEN** OpenRegister rejects it as failing the `minItems: 1` constraint

### Requirement: Competency is a recursive taxonomy node, and a leaf Competency is the learning outcome

The system MUST persist `Competency` as an OpenRegister object with `frameworkId` (required, `$ref:
CompetencyFramework`), `parentId` (nullable, `$ref: Competency` — recursive, mirroring `Course.
parentCourseId`), `code`, `title`, `description`, `order`, and `requiredForRoles[]` (array of the same role
strings as `LearnerProfile.roles`, default `[]`). `Competency` MUST NOT introduce a separate
`LearningOutcome` schema: a `Competency` with no children (`childCount == 0`, materialized via
`isLeaf`, an `x-openregister-calculations` boolean over an `x-openregister-aggregate-refs` count of child
`Competency` rows where `parentId == @self.id` — the same `lessonCount`/`isPublished` shape `Course`
already uses) **is** the learning outcome — the atomic, directly-alignable, directly-assessable node in
the tree, exactly as a leaf `Course` (no sub-`Course`s) is a directly-teachable unit under
`Course.parentCourseId`. `Competency` MUST carry `x-openregister-lifecycle` (`draft → published →
archived`, mirroring `Course`/`CompetencyFramework`).

#### Scenario: A kerntaak/werkproces hierarchy is a two-level Competency tree under one framework

<!-- @e2e exclude Pure OpenRegister schema shape and calculation; no scholiq DOM surface — covered by the register-validation test and a PHPUnit assertion on the isLeaf/childCount calculation shape referenced in tasks.md. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: sbb-kwalificatiedossier`
- **WHEN** a curriculum designer creates a kerntaak `Competency` (`parentId: null`) and three werkproces
  `Competency` rows beneath it (`parentId` set to the kerntaak's id)
- **THEN** the kerntaak's `childCount` calculates to 3 and `isLeaf` is `false`
- **AND** each werkproces row's `childCount` is 0 and `isLeaf` is `true` — each werkproces is a learning
  outcome in its own right, with no separate `LearningOutcome` object required

#### Scenario: A leerlijn/kerndoel hierarchy follows the same recursive shape

<!-- @e2e exclude Same calculation mechanism as the kerntaak/werkproces scenario; no separate DOM surface. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: slo-kerndoelen`
- **WHEN** a curriculum designer creates a leerlijn `Competency` and several kerndoel `Competency` rows
  beneath it
- **THEN** the same recursive `parentId`/`isLeaf` shape applies without any framework-specific schema
  branching

### Requirement: CompetencyAttainment is a declared, event-driven per-learner roll-up, never a TimedJob

The system MUST persist `CompetencyAttainment` as a read-only (`x-openregister.readOnly: true`), non-
lifecycled OpenRegister object — one row per `(learnerId, competencyId)` — mirroring `FinalGrade`'s exact
shape: `learnerId` (NC uid, required), `learnerRef` (nullable `$ref: LearnerProfile`, ADR-046 A4),
`competencyId` (required, `$ref: Competency`), `frameworkId` (required, `$ref: CompetencyFramework`,
denormalized for query convenience exactly as `FinalGrade.curriculumPlanId` is), `proficiencyLevelId`
(nullable string, the computed current level — matches a `CompetencyFramework.proficiencyLevels[].
levelId`), `gradeEntryIds`/`assessmentResultIds`/`werkprocesAssessmentIds`/`submissionIds` (each an array
of `format: uuid` `$ref`-typed evidence references, default `[]` — the relation-dialect-compliant array
form of `GradeEntry`'s single-value `sourceKind`-selected `$ref` fan-out, since one roll-up row
accumulates evidence from more than one event over time), and `lastRecomputedAt`. Recomputation MUST be
driven by `x-openregister-triggers.calculatedChange` naming a new
`OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler` — reacting to `GradeEntry`'s `publish`/`republish`
transitions (resolving `sourceKind: assignment-submission` via `Submission.assignmentId.competencyIds`
and `sourceKind: assessment-result` via `AssessmentResult.assessmentId.competencyIds`) and to
`WerkprocesAssessment`'s `confirm` transition directly (via its own generalized `competencyId`) —
registered in `lib/AppInfo/Application.php` alongside the existing `GradeRollupHandler`/
`WerkprocesGradeEmitHandler` listeners on the same `ObjectTransitionedEvent` class, NOT a PHP `TimedJob`
(ADR-022).

Note: `AssessmentResult.assessmentId` and `Submission.assignmentId` grade a whole `Assignment`/`Assessment`,
not an individual `Item`; per-`Item` attainment granularity is explicitly out of scope for this change (see
design.md) — `Item.competencyIds` is authoring/analytics metadata here, not yet consumed by the roll-up.

#### Scenario: A published GradeEntry from a competency-aligned Assignment creates or updates a CompetencyAttainment

<!-- @e2e exclude Backend event-driven roll-up logic; no scholiq DOM surface for a declared trigger firing — covered by PHPUnit CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** an `Assignment` with `competencyIds` containing one `Competency` UUID
- **AND** a learner's marked `Submission` for that `Assignment` whose `GradeEntry` transitions to
  `published`
- **WHEN** `CompetencyAttainmentRollupHandler` reacts to that transition
- **THEN** a `CompetencyAttainment` row for `(learnerId, competencyId)` is created (or updated if one
  already exists), the `GradeEntry`'s id is appended to `gradeEntryIds`, the `Submission`'s id is appended
  to `submissionIds`, and `proficiencyLevelId` is recomputed against the competency's framework scale

#### Scenario: A confirmed WerkprocesAssessment updates its generalized Competency's attainment directly

<!-- @e2e exclude Backend event-driven roll-up logic on the bpv object; no scholiq DOM surface — covered by PHPUnit CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** a `WerkprocesAssessment` whose generalized `competencyId` resolves to a werkproces `Competency`
- **WHEN** the `WerkprocesAssessment` transitions `submitted → confirmed`
- **THEN** `CompetencyAttainmentRollupHandler` (registered alongside `WerkprocesGradeEmitHandler` on the
  same transition) creates or updates the `(learnerId, competencyId)` `CompetencyAttainment` row and
  appends the `WerkprocesAssessment`'s id to `werkprocesAssessmentIds`
- **AND** `proficiencyLevelId` maps directly from `beoordeling` to the matching `levelId` on the
  kwalificatiedossier framework's `proficiencyLevels` (`competent` → the highest level, `nog-niet-competent`
  → the lowest), the same binary-scale precedent `WerkprocesGradeEmitHandler` already uses when mapping
  `beoordeling` onto `GradeEntry.value`

#### Scenario: Evidence with no declared competency alignment leaves attainment untouched

<!-- @e2e exclude Negative-path backend behaviour; no DOM surface — covered by PHPUnit CompetencyAttainmentRollupHandlerTest::testUnalignedAssignmentEvidenceIsNoOp. -->

- **GIVEN** a `GradeEntry` whose source `Assignment`/`Assessment` has `competencyIds: []`
- **WHEN** that `GradeEntry` publishes
- **THEN** `CompetencyAttainmentRollupHandler` performs no write — no `CompetencyAttainment` row is
  created or updated

### Requirement: CompetencyAttainment read access includes the roles the dashboard spec already promises

`CompetencyAttainment`'s `x-property-rbac` read rule MUST grant `admin`, `hr`, and `manager` roles
unrestricted read (grounded in `openspec/specs/dashboard/spec.md:22`, which already names `HR/manager
(team learning progress, time-to-competence)` as a dashboard audience with no backing data until this
change), plus the matching learner (`learnerId == $userId`) reading their own rows — the same
self-plus-privileged-roles shape `FinalGrade`/`GradeEntry` already use, extended with the two roles this
object's stated audience specifically needs.

#### Scenario: A manager reads team attainment; a learner reads only their own

<!-- @e2e exclude RBAC read-scoping is a backend authorization rule with no distinct DOM surface beyond the declarative pages other specs already cover — covered by PHPUnit register/RBAC assertions referenced in tasks.md. -->

- **GIVEN** `CompetencyAttainment` rows for several learners
- **WHEN** a `manager` or `hr` role reads the collection
- **THEN** every row is visible
- **AND** WHEN a `learner` role reads the collection THEN only rows where `learnerId` equals their own
  user id are visible

### Requirement: Skills-gap view compares required competencies (by Programme and by role) against attained ones

The frontend MUST be declarative: `src/manifest.json` read-only index/detail pages for
`CompetencyFramework`, `Competency`, and `CompetencyAttainment` (no create/edit actions rendered for
`CompetencyAttainment` — it is system-derived). The only custom Vue component MUST be
`SkillsGapDashboard.vue`, which computes the union of a learner's required competencies —
`Programme.requiredCompetencyIds` for their enrolled programme(s) plus any `Competency` whose
`requiredForRoles` intersects their `LearnerProfile.roles` — against their `CompetencyAttainment` rows,
and lists any required competency with no attainment row, or an attained `proficiencyLevelId` below the
framework's declared pass/target level, as a gap. No PHP CRUD controller.

#### Scenario: A learner sees an unmet Programme-required competency as a gap

<!-- @e2e tests/e2e/spec-coverage/competency-framework.spec.ts -->

- **GIVEN** a `Programme` whose `requiredCompetencyIds` includes a `Competency` the learner has no
  `CompetencyAttainment` row for
- **WHEN** the learner (or their manager) opens the Skills Gap dashboard
- **THEN** that competency is listed as a gap, distinct from competencies with an attainment row at or
  above the framework's target level

#### Scenario: A role-required competency surfaces even without a Programme link

<!-- @e2e tests/e2e/spec-coverage/competency-framework.spec.ts -->

- **GIVEN** a `Competency` with `requiredForRoles` containing `"instructor"`
- **AND** a learner whose `LearnerProfile.roles` includes `"instructor"`, with no `Programme` linking them
  to that competency
- **WHEN** the Skills Gap dashboard loads for that learner
- **THEN** the role-required competency is included in the required set, independent of any Programme
  enrolment

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

### Requirement: A coverage matrix shows goals by year with planned and assessed marked

The frontend MUST offer one custom page, `CurriculumCoverageMatrix` at `/curriculum/coverage`, rendering the registered
component `CurriculumCoverageMatrixView`, reachable from a "Curriculum coverage" entry in the Learning menu next to
"Curriculum" and visible to the staff roles that may read `CurriculumCoverage` (instructor, coordinator, team lead,
administration manager, admin). It MUST NOT also be a card on the Reports page. The page MUST render a read-only
`CnDataMatrix` built from the selected framework's `CurriculumCoverage` rows and `Competency` rows: the active goals as
rows in tree order with each domain as a heading row, the framework's year labels as columns in natural order (a
single "All years" column when there are none), and in each leaf goal's cell, only for the years the goal applies to,
one of "Planned and assessed", "Planned", "Assessed" or "Not covered" plus the deepest depth's level label. Status MUST
be carried by words, not colour alone. A framework and a subject filter (all subjects, each subject, no subject) MUST
narrow the matrix, and `?framework=<id>` MUST preselect a framework. The page MUST say that it shows the plan, not
what learners have mastered.

#### Scenario: A coordinator sees which goals of groep 5 are taught and tested

<!-- @e2e exclude Lanes may not drive the shared instance; the layout rules live in pure builders covered by tests/unit-js/curriculumCoverage.test.mjs ("the matrix shows goals under their domain, years as columns, cells only in a goal's years"). A Playwright pass is a follow-up once the rows can be seeded. -->

- **GIVEN** a framework whose domain "Getallen" holds K1 (planned and assessed, deepest depth "master") and K2
  (planned only), both for groep 5, and a root goal K3 that applies to every year and nothing covers
- **WHEN** a coordinator opens Curriculum coverage and picks that framework
- **THEN** the matrix lists Getallen as a heading, then K1 and K2 under it, then K3
- **AND** the groep 5 column reads "Planned and assessed (master)" for K1, "Planned" for K2 and "Not covered" for K3
- **AND** K1 and K2 show nothing in any other year column

#### Scenario: Picking a subject narrows the matrix

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("a subject selection narrows the rows; a framework without years gets one column"). -->

- **GIVEN** the framework above, with K1 and K2 linked to the subject rekenen
- **WHEN** the coordinator picks rekenen in the subject filter
- **THEN** the matrix shows Getallen, K1 and K2 only, with the groep 5 column

#### Scenario: The report is a menu entry, not a card

<!-- @e2e exclude Manifest shape; covered by tests/unit-js/curriculumCoverage.test.mjs ("the page is a menu entry and not a Reports card") and the registry coverage test. -->

- **GIVEN** the merged manifest
- **WHEN** the Learning menu and the Reports page are read
- **THEN** a "Curriculum coverage" entry routes to `CurriculumCoverageMatrix`, and no Reports card does

### Requirement: A gap list names the uncovered goals per subject and year

Below the matrix the page MUST list, per subject (by course name, or "No subject") and per year (or "All years" when
the framework has no years), the goals nothing aligns to ("Not covered") and the goals planned but never assessed
("Planned, not assessed"), read from the `uncoveredIds` and `plannedNotAssessedIds` of the per-subject coverage rows.
Sections with no gap MUST be left out, and when no section remains the page MUST say that every goal is planned and
assessed. A framework without coverage rows yet MUST show a notice that coverage fills in when a goal, lesson,
course, assignment or assessment in it is saved, not an empty grid.

#### Scenario: The gap list shows what to plan and what to test

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("the gap list names uncovered and untested goals per subject and year"). -->

- **GIVEN** the framework above
- **WHEN** the page renders
- **THEN** the gap list has "No subject · groep 5" with K3 under "Not covered"
- **AND** "Rekenen · groep 5" with K2 under "Planned, not assessed"
- **AND** no section for a subject and year without gaps

#### Scenario: A framework without coverage yet explains itself

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("no coverage rows give an empty matrix, not an error") plus the notice in the view template. -->

- **GIVEN** a framework with no `CurriculumCoverage` rows
- **WHEN** it is picked
- **THEN** the page shows the notice that coverage fills in on the next save, and no matrix

### Requirement: A Competency declares the years it is taught in

`Competency` MUST declare an optional `applicableYears` property: an array of string labels (`uniqueItems:
true`, each item `minLength: 1` and `maxLength: 64`), default `[]`, with no enum. A label is either a year level
(canonical spelling `groep 1` to `groep 8`, `leerjaar 1` to `leerjaar 6`, `jaar 1` to `jaar 4`) or an academic
year in the `Cohort.academicYear` format (`YYYY` or `YYYY-YYYY`). An empty array MUST mean the goal applies to
every year of its framework, so every `Competency` stored before this change stays valid and in scope for every
year. The property is additive: `frameworkId`, `parentId`, `code`, `title`, `description`, `order`,
`requiredForRoles` and `lifecycle` keep their names, types and meaning.

#### Scenario: A kerndoel is allocated to two year levels

<!-- @e2e exclude Pure register shape with no learniq DOM surface of its own; the existing Competency data widget and form render every schema property. Covered by CompetencyYearScopeRegisterTest::testApplicableYearsIsAnOptionalFreeLabelArray. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: slo-kerndoelen`
- **WHEN** a curriculum designer saves a leaf `Competency` under it with `applicableYears: ["groep 5", "groep 6"]`
- **THEN** the object persists with both labels
- **AND** a second save with `applicableYears: ["groep 5", "groep 5"]` is rejected by the `uniqueItems` constraint

#### Scenario: A Competency stored before this change applies to every year

<!-- @e2e exclude Back-compat default on a register property; no DOM surface. Covered by CompetencyYearScopeRegisterTest::testNewPropertiesAreAdditiveAndOptional. -->

- **GIVEN** a `Competency` row created before this change, with no `applicableYears`
- **WHEN** it is read
- **THEN** `applicableYears` resolves to `[]`, meaning the goal is in scope for every year of its framework
- **AND** `required` on `Competency` is unchanged (`frameworkId`, `code`, `title`, `tenant_id`)

### Requirement: A Competency declares the subject it belongs to

`Competency` MUST declare an optional `subjectId` property: a nullable string with `format: uuid` and `$ref:
Course`, default `null`. learniq models a subject as a `Course` row, the same reference
`SubjectTeacherAssignment.courseId` uses, so `subjectId` MUST NOT reference any other schema. A null value MUST
mean the goal is cross-subject or not yet linked.

#### Scenario: A rekenen-wiskunde goal is linked to the school's rekenen course

<!-- @e2e exclude Pure register shape; the relation renders through the existing Related panel on CompetencyDetail. Covered by CompetencyYearScopeRegisterTest::testSubjectIdReferencesCourse. -->

- **GIVEN** a `Course` row named "Rekenen-wiskunde" standing for the subject
- **WHEN** a curriculum designer sets a `Competency`'s `subjectId` to that course's UUID
- **THEN** the object persists with the reference
- **AND** the `Competency` detail page resolves `subjectId` to the course through its Related panel

### Requirement: Readers resolve an empty year or subject from the nearest ancestor

Any reader that groups `Competency` rows by year or subject (the coverage rollup in learniq, and the SLO
importer in integriq when it decides where to set a value) MUST resolve an effective value per node: the
node's own non-empty `applicableYears`, else the nearest ancestor's (via `parentId`) non-empty
`applicableYears`, else `[]`; and the node's own non-null `subjectId`, else the nearest ancestor's non-null
`subjectId`, else `null`. Labels MUST be compared after trimming whitespace and lower-casing. The effective
value MUST NOT be written back onto the node: it is a read rule, so editing a domain node's value changes every
descendant that has no value of its own.

#### Scenario: Years set on a domain node apply to its kerndoelen

<!-- @e2e exclude Read rule for a server-side consumer that ships in curriculum-coverage-rollup; no DOM surface in this change. Pinned by the spec text and by CompetencyYearScopeRegisterTest::testDescriptionsStateTheInheritanceRule. -->

- **GIVEN** a domain `Competency` with `applicableYears: ["groep 7", "groep 8"]` and `subjectId` set to the
  rekenen course
- **AND** two child kerndoelen with `applicableYears: []` and `subjectId: null`
- **WHEN** a reader resolves the effective year and subject of each child
- **THEN** both children resolve to `groep 7` and `groep 8` and to the rekenen course
- **AND** neither child row is modified

#### Scenario: A child's own value wins over its parent's

<!-- @e2e exclude Same read rule as above; no DOM surface in this change. -->

- **GIVEN** a domain `Competency` with `applicableYears: ["groep 7", "groep 8"]`
- **AND** a child kerndoel with `applicableYears: ["groep 8"]`
- **WHEN** a reader resolves the child's effective years
- **THEN** it resolves to `groep 8` only

### Requirement: Lessons, courses, assignments and assessments align to goals with a depth

`Lesson`, `Course`, `Assignment` and `Assessment` MUST each declare an optional `competencyAlignments` property:
an array (default `[]`) of objects with `competencyId` (required, `format: uuid`, `$ref: Competency`) and `depth`
(nullable string, `maxLength: 64`). `depth` MUST be either `null` (depth not set) or a `levelId` from the
`proficiencyLevels` of the `CompetencyFramework` that owns the aligned `Competency` (plan assumption A4). No fixed
depth enum exists. The existing `competencyIds` property on the four schemas MUST stay, with its type and meaning
unchanged. `Item.competencyIds` MUST NOT gain alignments: it stays authoring metadata.

#### Scenario: A lesson practises one goal and introduces another

<!-- @e2e exclude Register shape plus a pre-save listener; the property renders through the existing Lesson form and data widget. Covered by GoalAlignmentDepthRegisterTest and CompetencyAlignmentListenerTest::testAlignmentsDeriveCompetencyIds. -->

- **GIVEN** a `CompetencyFramework` whose `proficiencyLevels` are `introduce`, `practise` and `master`
- **AND** two leaf `Competency` rows under it
- **WHEN** a teacher saves a `Lesson` with `competencyAlignments: [{competencyId: <goal A>, depth: "practise"},
  {competencyId: <goal B>, depth: "introduce"}]`
- **THEN** the lesson persists with both alignments
- **AND** its `competencyIds` is `[<goal A>, <goal B>]`

#### Scenario: A depth may be left open

<!-- @e2e exclude Same listener path; covered by CompetencyAlignmentListenerTest::testNullDepthIsAccepted. -->

- **GIVEN** a leaf `Competency`
- **WHEN** a teacher saves an `Assignment` with `competencyAlignments: [{competencyId: <goal>, depth: null}]`
- **THEN** the assignment persists, and its `competencyIds` is `[<goal>]`

### Requirement: competencyIds stays derived from the alignments

On create and update of `Lesson`, `Course`, `Assignment` and `Assessment`, a pre-save listener
(`CompetencyAlignmentListener`, registered on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`) MUST
keep the two lists in step:

- When `competencyAlignments` differs from the stored value (or is non-empty on create), `competencyIds` MUST be
  set to the alignments' `competencyId` values, in alignment order, without duplicates. An alignment list emptied
  on update MUST empty `competencyIds`.
- When only `competencyIds` changes on a row whose stored alignments are non-empty, `competencyAlignments` MUST
  follow: an alignment whose goal is still listed keeps its depth, a newly listed goal gets `depth: null`, and an
  alignment whose goal is no longer listed is dropped.
- When both change in one save, the alignments MUST win.
- A row that never had alignments MUST keep its `competencyIds` exactly as written.

The listener MUST act only on objects in the `learniq` register with one of the four schema slugs, and MUST NOT
fail another app's write when it cannot resolve a schema.

#### Scenario: A legacy row keeps its flat list

<!-- @e2e exclude Back-compat listener path; covered by CompetencyAlignmentListenerTest::testLegacyRowIsLeftAlone. -->

- **GIVEN** a `Course` saved with `competencyIds: [<goal>]` and no `competencyAlignments`
- **WHEN** the listener handles the save
- **THEN** it writes nothing, and `competencyIds` stays `[<goal>]`

#### Scenario: Editing only the flat list updates the alignments

<!-- @e2e exclude Listener sync path; covered by CompetencyAlignmentListenerTest::testFlatListEditUpdatesAlignments. -->

- **GIVEN** a stored `Lesson` with `competencyAlignments: [{competencyId: <goal A>, depth: "master"}, {competencyId:
  <goal B>, depth: "introduce"}]`
- **WHEN** an update changes only `competencyIds` to `[<goal A>, <goal C>]`
- **THEN** `competencyAlignments` becomes `[{competencyId: <goal A>, depth: "master"}, {competencyId: <goal C>,
  depth: null}]`

#### Scenario: Emptying the alignments empties the flat list

<!-- @e2e exclude Listener sync path; covered by CompetencyAlignmentListenerTest::testEmptiedAlignmentsEmptyTheFlatList. -->

- **GIVEN** a stored `Assessment` with one alignment and `competencyIds: [<goal>]`
- **WHEN** an update sets `competencyAlignments: []`
- **THEN** `competencyIds` becomes `[]`

### Requirement: A depth the goal's framework does not know is refused

Before a `Lesson`, `Course`, `Assignment` or `Assessment` with changed `competencyAlignments` is saved, the listener
MUST refuse the write (OpenRegister reject mode: `setErrors` plus `stopPropagation`) when any alignment names a
`competencyId` that does not resolve to a `Competency`, names the same `competencyId` twice, or carries a non-null
`depth` that is not a `levelId` of the aligned goal's framework. The refusal message MUST name the goal's code and
the allowed level ids, so a teacher can correct it without looking them up.

#### Scenario: A depth from another framework is refused

<!-- @e2e exclude Reject-mode listener path; covered by CompetencyAlignmentListenerTest::testUnknownDepthIsRefusedWithTheAllowedLevels. -->

- **GIVEN** a goal whose framework's levels are `nog-niet-competent` and `competent`
- **WHEN** a teacher saves an `Assignment` aligning that goal with `depth: "master"`
- **THEN** the save is refused
- **AND** the message names the goal's code and the levels `nog-niet-competent` and `competent`

#### Scenario: The same goal twice is refused

<!-- @e2e exclude Reject-mode listener path; covered by CompetencyAlignmentListenerTest::testDuplicateGoalIsRefused. -->

- **GIVEN** a leaf `Competency`
- **WHEN** a teacher saves a `Course` with two alignments naming that goal
- **THEN** the save is refused with a message that names the goal's code

### Requirement: Readers treat a flat-only row as alignments without depth

Any reader of goal links (the coverage rollup first) MUST resolve a row's effective alignments as its
`competencyAlignments` when non-empty, else its `competencyIds` mapped to `{competencyId, depth: null}`. A stored
depth that is no longer a `levelId` of the framework MUST be read as `null`, never as a missing link.

#### Scenario: A course from before this change counts as aligned without depth

<!-- @e2e exclude Read rule for the rollup that ships in curriculum-coverage-rollup; pinned here by CompetencyAlignmentNormaliserTest::testEffectiveAlignmentsFallBackToTheFlatList. -->

- **GIVEN** a `Course` with `competencyIds: [<goal>]` and no alignments
- **WHEN** a reader resolves its effective alignments
- **THEN** it gets `[{competencyId: <goal>, depth: null}]`

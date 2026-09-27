# Design: curriculum-coverage-rollup

## Architecture Overview
```
save Lesson / Course / Assignment / Assessment(exam) / Competency / CompetencyFramework
  -> OpenRegister ObjectCreatedEvent | ObjectUpdatedEvent | ObjectDeletedEvent  (post-save)
     -> ObjectEventSubscription (register learniq, the six schema slugs)
        -> CurriculumCoverageRollupHandler          thin trigger, never throws
             frameworks touched = frameworks of old+new goals | the goal's old+new framework | the framework itself
             -> CurriculumCoverageRollup::recompute(frameworkId)       I/O
                  load framework, its goals, and the rows that align to them
                  (one "contains any" findAll per schema on competencyIds, goal ids chunked by 100)
                  -> CurriculumCoverageCalculator::compute()           pure
                  upsert changed rows, delete vanished buckets
occ learniq:curriculum-coverage:recompute [--framework=<id>]  -> CurriculumCoverageRollup
```

It mirrors FinalGrade and CompetencyAttainment: a read-only derived schema written by a listener on OpenRegister
events, never a TimedJob. `competencyIds` is the query key because `goal-alignment-depth` keeps it derived from the
alignments; the calculator reads depth through `CompetencyAlignmentNormaliser::effectiveAlignments()`.

## Schema: `CurriculumCoverage` (slug `curriculum-coverage`)

| Property | Type | Notes |
|---|---|---|
| `frameworkId` | uuid, `$ref CompetencyFramework`, required | |
| `year` | string, nullable, maxLength 64 | canonical label (trimmed, lower case); `null` = all years |
| `subjectScope` | enum `all`, `subject`, `none`, required | with `x-enum-labels` |
| `subjectId` | uuid, nullable, `$ref Course` | set only for `subject` |
| `goalCount`, `plannedCount`, `assessedCount`, `plannedNotAssessedCount`, `uncoveredCount` | integer | |
| `plannedPercent`, `assessedPercent` | number | one decimal; 0 when `goalCount` is 0 |
| `plannedByDepth`, `assessedByDepth` | array of `{depth: string or null, count: integer}` | framework level order, `null` last, zero counts left out |
| `uncoveredIds`, `plannedNotAssessedIds` | array of uuid, `$ref Competency` | |
| `goals` | array of `{competencyId, planned, assessed, plannedDepth, assessedDepth, plannedRefs, assessedRefs}` | the goals this row counts, in framework tree order |
| `lastRecomputedAt` | date-time | |
| `tenant_id` | uuid, required | copied from the framework |

`x-openregister.readOnly: true`, no lifecycle, no seed rows (derived), demo rows in the mock register.
Authorization mirrors `ItemStatistics`: read `instructors`, `team-leads`, `coordinators`, `administration-managers`,
`compliance-officers`; create and update the cascade writers `instructors`, `hr`, `compliance-officers`, `team-leads`.

## Decisions

### D1: One row per framework × year × subject, with explicit totals
The matrix (change 4) needs per-goal status, and reports need counts per subject and year. Explicit total rows
(`year: null`, `subjectScope: all`) let a report read a total without summing, and `subjectScope: none` keeps the
subject rows adding up to the total. An enum beats overloading `subjectId: null`, which would mean both "all" and
"none".

### D2: A reference is not scoped to a year
A lesson or course has no year field. A goal therefore counts as planned in every year it applies to once anything
aligns to it. The matrix shows the status only in the goal's own year columns, so this reads correctly. Scoping by
the course's cohort year is a possible later change.

### D3: Leaves only, deepest depth per kind
A leaf goal is the learning outcome (the `competency` spec). A reference to a domain node is too vague to cover its
leaves, so it is not counted. Depth keeps the deepest level by `proficiencyLevels[].order` (array position when
`order` is missing); a stale depth reads as `null`, via `effectiveAlignments`.

### D4: Which rows count
Draft rows count, because planning happens in drafts. Left out: archived goals, retired lessons, archived courses,
assignments and assessments. A closed assignment or assessment still counts as assessed.

### D5: Write only what changed
The rollup compares each computed row with the stored one (ignoring `id` and `lastRecomputedAt`) and saves only
differences, so a typical lesson edit writes one to a few rows. Saves use `_rbac: false`, `_multitenancy: false`: the
rows are system-derived, and the tenant is copied from the framework.

### D6: An occ command, not an HTTP endpoint, for backfill
Rows only appear after a save. The command fills them for existing data without adding a route, an IDOR surface or
a controller.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Store coverage rows | Declarative schema in the register | Plain derived data |
| Count goals per framework, year and subject | Imperative, pure calculator + listener (ADR-031 exception, cross-schema aggregation) | Aggregates many Lesson/Course/Assignment/Assessment rows over a recursive goal tree with inheritance; `x-openregister-aggregations` counts one schema by equality filters and the DSL has no division |
| Percentages | Computed in the calculator | No division in the calculation DSL (noted in `BootListenerRegistrar`) |
| Recompute trigger | Post-save listener on OpenRegister events | Same as FinalGrade/CompetencyAttainment; never a TimedJob (ADR-022) |

## Security Considerations
The rows hold curriculum metadata only (goal ids, counts), no learner data. Read is staff-only. The listener and the
command read goals, frameworks and the four referencing schemas with `_rbac: false` because the rollup is a system
computation; nothing read is returned to the caller, only counted. The command is admin-only by nature of occ.

## Nextcloud Integration
- Controllers: none.
- Services: `CurriculumCoverageCalculator` (pure), `CurriculumCoverageRollup` (uses OpenRegister `ObjectService`).
- Events/Hooks: OpenRegister `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` through
  `ObjectEventSubscription` in `BootListenerRegistrar`.
- Commands: `OCA\Learniq\Command\RecomputeCurriculumCoverage` in `appinfo/info.xml`.

## File Structure
```
lib/Settings/learniq_register.json                       CurriculumCoverage; info.version
lib/Service/CurriculumCoverageCalculator.php             new, pure
lib/Service/CurriculumCoverageRollup.php                 new
lib/Listener/CurriculumCoverageRollupHandler.php         new
lib/Command/RecomputeCurriculumCoverage.php              new
lib/AppInfo/Registrar/BootListenerRegistrar.php          three filtered registrations
appinfo/info.xml                                          <commands>
tests/Stubs/Service/ObjectService.php                    deleteObject
tests/Stubs/Event/ObjectDeletedEvent.php                 new
tests/Unit/Service/CurriculumCoverageCalculatorTest.php  new
tests/Unit/Service/CurriculumCoverageRollupTest.php      new
tests/Unit/Listener/CurriculumCoverageRollupHandlerTest.php new
tests/Unit/Command/RecomputeCurriculumCoverageTest.php   new
tests/Unit/Settings/CurriculumCoverageRegisterTest.php   new
lib/Settings/learniq_mock_register.json, l10n/*          demo rows, keys
```

## Seed Data
Derived rows get no `x-openregister-seed`. Three demo rows in `learniq_mock_register.json` show the three subject
scopes of one framework's `groep 5` column:

### Schema: `curriculum-coverage`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `curriculum-coverage-groep-5-all` | `curriculum-coverage-groep-5-rekenen` | `curriculum-coverage-groep-5-none` |
| `frameworkId` | `00000000-0000-4000-8000-000000000001` | same | same |
| `year` | `groep 5` | `groep 5` | `groep 5` |
| `subjectScope` | `all` | `subject` | `none` |
| `subjectId` | null | `00000000-0000-4000-8000-000000000000` | null |
| `goalCount` / planned / assessed | 3 / 2 / 1 | 2 / 2 / 1 | 1 / 0 / 0 |
| `uncoveredIds` | `[goal 3]` | `[]` | `[goal 3]` |

Goal ids use the placeholder pattern already in the demo file. **Related items per object:** none.

## Trade-offs
A synchronous recompute costs time in the save that triggers it. The alternative, a queued job per save, would show
stale coverage right after a teacher aligns a lesson and adds a job type to operate. With chunked lookups and
change-only writes the synchronous path stays small for realistic framework sizes (tens to a few hundred leaf goals).

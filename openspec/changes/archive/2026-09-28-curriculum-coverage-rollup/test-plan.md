# Test Plan: curriculum-coverage-rollup

Every scenario is pinned by PHPUnit. No browser test: this change has no page (the view is change 4).

### TC-1: Staff-only, read-only, lifecycle-free schema
- **spec_ref**: `specs/competency/spec.md#requirement-curriculumcoverage-is-a-derived-read-only-coverage-object-per-framework-year-and-subject`
- **type**: security
- **preconditions**: the register
- **steps**: read `CurriculumCoverage`
- **expected result**: readOnly, no lifecycle, staff read groups only, cascade writers, description says planning signal
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageRegisterTest`

### TC-2: Planned and assessed counted separately
- **spec_ref**: `#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately`
- **type**: regression
- **preconditions**: three leaf goals, a lesson on A and B, an assignment on A
- **steps**: compute
- **expected result**: planned 2, assessed 1, planned-not-assessed [B], uncovered [C]
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageCalculatorTest`

### TC-3: Leaves only; archived and retired left out; deepest depth; flat-only rows count
- **spec_ref**: `#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately`
- **type**: regression
- **preconditions**: a domain node, an archived leaf, a retired lesson, depths introduce/master/null
- **steps**: compute
- **expected result**: as in the spec scenarios
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageCalculatorTest`

### TC-4: Buckets with inheritance and totals; empty framework
- **spec_ref**: `#requirement-coverage-is-bucketed-by-year-and-subject-with-totals`
- **type**: regression
- **preconditions**: the groep 5 / rekenen tree of the spec; an empty framework
- **steps**: compute
- **expected result**: the six rows of the spec scenario; one total row for the empty framework
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageCalculatorTest`

### TC-5: Upsert only changed rows, delete vanished buckets, missing framework
- **spec_ref**: `#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob`
- **type**: regression
- **preconditions**: stored rows, one unchanged, one orphaned
- **steps**: recompute
- **expected result**: unchanged not saved, orphan deleted; missing framework deletes all its rows
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageRollupTest`

### TC-6: Listener recomputes only touched frameworks and never throws
- **spec_ref**: `#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob`
- **type**: regression
- **preconditions**: lesson moving from F1 to F2; goal save; framework save; throwing rollup
- **steps**: dispatch events
- **expected result**: F1 and F2 only; goal's frameworks; the framework; exception logged
- **test command**: `vendor/bin/phpunit --filter CurriculumCoverageRollupHandlerTest`

### TC-7: occ backfill
- **spec_ref**: `#requirement-an-occ-command-fills-coverage-for-existing-data`
- **type**: regression
- **preconditions**: three frameworks; an unknown id
- **steps**: run the command with and without `--framework`
- **expected result**: 3 recomputed, exit 0; one recomputed; unknown id exit 1
- **test command**: `vendor/bin/phpunit --filter RecomputeCurriculumCoverageTest`

## Coverage Summary
All five requirements are covered by TC-1 to TC-7.

## Out of Scope
Live recompute timing on a real instance: lanes may not touch the instance on :8080. `test-performance` is a follow-up
once the matrix view (change 4) exists.

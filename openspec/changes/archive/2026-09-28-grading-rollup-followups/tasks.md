# Tasks: grading-rollup-followups

## Implementation Tasks

### Task 1: Write FinalGrade.programmeId
- **spec_ref**: `openspec/changes/grading-rollup-followups/specs/grading/spec.md#requirement-the-final-grade-roll-up-writes-the-programme-it-belongs-to`
- **files**: `lib/Listener/GradeRollupHandler.php`, `tests/Unit/Listener/GradeRollupHandlerTest.php`
- [x] Test written first and red
- [x] Implement

### Task 2: Defer the competency roll-up
- **spec_ref**: `openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it`
- **files**: `lib/Listener/CompetencyAttainmentRollupHandler.php`, `lib/Service/CompetencyAttainmentRollup.php`, `lib/BackgroundJob/CompetencyAttainmentRollupJob.php`, their tests
- [x] Test written first and red
- [x] Implement; gate 61 no longer names the handler

## Verification
- [x] `openspec validate grading-rollup-followups` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

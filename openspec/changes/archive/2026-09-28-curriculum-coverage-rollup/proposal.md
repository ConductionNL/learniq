---
kind: code
depends_on:
  - competency-year-scope
  - goal-alignment-depth
---

# Proposal: curriculum-coverage-rollup

## Summary
A school cannot see today whether the goals of a subject and year are covered. This change adds a derived, read-only `CurriculumCoverage` object per framework, year and subject. It counts the goals in scope, how many are planned (a lesson or course aligns to them), how many are assessed (an assignment or assessment does), and lists the goals nothing refers to. A post-save listener keeps it current, following the FinalGrade and CompetencyAttainment pattern, and an occ command fills it for data that existed before.

## Motivation
Round 2 recon B (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/B-curriculum-goals-coverage.md`, section 1, row "Coverage per subject / per year / in total (the PO's core ask)") found it "Missing. No aggregation counts how many leaf Competencies under this framework are referenced by at least one Lesson/Course this year." `CompetencyAttainment` answers a different question: did this learner show the goal.

Curriculum-mapping tools show coverage as taught separately from assessed, and surface gaps as a list, not only a colour (recon B section 2, Atlas gap and coverage reports, onatlas.com/atlas-features, read 2026-09-27). SERA Datawijzer imports SLO goals into leerlijnen for exactly this check (round 1 row L-new-7, `_round1/compare/proposed-rows.md:222`).

Plan assumption A3 decides what "covered" means: planned and assessed are tracked separately, so a school sees which goals are taught but never tested. Recon B section 6 warns that coverage is a planning signal, not evidence of learning; the object is named and described so it cannot be read as attainment.

## Affected Projects
- [x] Project: `learniq`: a new `CurriculumCoverage` schema; a pure calculator, a rollup service, a post-save listener and an occ command; demo rows, catalogue keys and tests.

## Scope

### In Scope
- `CurriculumCoverage` (slug `curriculum-coverage`), read-only, no lifecycle, staff-only read: one row per framework, per year label (plus an all-years row) and per subject (plus an all-subjects row and a no-subject row).
- Per row: goals in scope, planned, assessed, planned but not assessed, not covered, the two percentages, goals by deepest depth (planned and assessed), the ids of uncovered and planned-but-not-assessed goals, and a per-goal detail list for the matrix view.
- Only leaf goals count (a leaf is the learning outcome). Archived goals are left out; retired lessons and archived courses, assignments and assessments do not count as references.
- Years and subject resolve through the read rule of `competency-year-scope` (an empty value inherits from the nearest ancestor). Links resolve through the read rule of `goal-alignment-depth` (`effectiveAlignments`).
- `CurriculumCoverageRollupHandler` on `ObjectCreatedEvent`, `ObjectUpdatedEvent` and `ObjectDeletedEvent` for lessons, courses, assignments, assessments, goals and frameworks; it recomputes only the frameworks the write touched and saves only rows whose numbers changed.
- `occ learniq:curriculum-coverage:recompute [--framework=<id>]` to fill coverage for existing data.

### Out of Scope
- The matrix and gap-list view. That is `curriculum-coverage-matrix-view`, change 4 of this lane.
- Scoping a reference to a year. A lesson or course carries no year, so a goal counts as planned in every year it applies to once anything aligns to it. A later change could scope references by the course's cohort year.
- Any link to `CompetencyAttainment` or per-learner data. Coverage is curriculum metadata only (recon B section 6).

## Approach
One read-only schema in the register. The counting lives in a pure `CurriculumCoverageCalculator` (no I/O, fully unit-tested). `CurriculumCoverageRollup` loads one framework's goals and the rows that align to them (one "contains any" query per schema on the derived `competencyIds`), runs the calculator and upserts the rows. The listener is a thin trigger, registered through `ObjectEventSubscription` like `EnrolmentProgressRollupHandler`.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: new `CurriculumCoverage` schema; `info.version` minor bump.
- New: `lib/Service/CurriculumCoverageCalculator.php`, `lib/Service/CurriculumCoverageRollup.php`, `lib/Listener/CurriculumCoverageRollupHandler.php`, `lib/Command/RecomputeCurriculumCoverage.php`.
- `lib/AppInfo/Registrar/BootListenerRegistrar.php`: three filtered registrations. `appinfo/info.xml`: the command.
- `tests/Stubs/Service/ObjectService.php` gains `deleteObject`; `tests/Stubs/Event/ObjectDeletedEvent.php` is new.
- `lib/Settings/learniq_mock_register.json`, `l10n/*`: demo rows and keys.

## Cross-Project Dependencies
None. Stacked on `goal-alignment-depth` (PR 1024), which is stacked on `competency-year-scope` (PR 1019).

## Risks

### Risk 1: A save in a large framework recomputes many rows
**Severity:** Medium. **Mitigation:** only the touched frameworks recompute, goal lookups are chunked, and a row is written only when its numbers changed. The rollup never throws into the save that triggered it.

### Risk 2: Coverage read as mastery
**Severity:** Medium. **Mitigation:** the schema says "planned" and "assessed", never "attained" or "mastered", its description states it is not evidence of learning, and it holds no learner data.

### Risk 3: Stale rows after a label or subject disappears
**Severity:** Low. **Mitigation:** each recompute deletes the framework's rows whose year or subject bucket no longer exists.

## Rollback Strategy
Revert the diff. `CurriculumCoverage` rows are derived and nothing else reads them, so they can be left in place or deleted.

## Open Questions
None.

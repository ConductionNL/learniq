---
kind: code
depends_on:
  - curriculum-coverage-rollup
---

# Proposal: curriculum-coverage-matrix-view

## Summary
Coverage is counted now, but nobody can see it. This change adds one page, "Curriculum coverage", under the Curriculum entry of the Learning menu. It shows a framework's goals as rows under their domains, its years as columns, and in each cell whether the goal is planned, assessed, both or neither. Below the matrix a gap list names, per subject and year, the goals nothing covers and the goals that are taught but never tested.

## Motivation
Round 2 recon B (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/B-curriculum-goals-coverage.md`, section 1, row "Coverage matrix / gap-list UI (goal × lesson or goal × year)") found it "Missing. `CnDataMatrix` ... is used exactly once today, for `CohortGradebookView`". The data exists since `curriculum-coverage-rollup`; this change is the reading of it.

Curriculum-mapping tools show coverage per grade and discipline and surface gaps as a list, not only a colour (recon B section 2, Atlas scope-and-sequence views and gap reports, onatlas.com/atlas-features, read 2026-09-27). SERA Datawijzer's leerlijnen import (round 1 row L-new-7) exists so schools can run exactly this check.

Plan assumption A3 shapes the cells: planned and assessed are shown separately. Recon B section 6 shapes the copy: the page says it shows the plan, not what learners have mastered.

## Affected Projects
- [x] Project: `learniq`: a new custom view `CurriculumCoverageMatrixView` with pure builders in `src/utils/curriculumCoverage.js`, its page and menu entry in `src/manifest.d/learning.json`, its registry entry, catalogue keys, a node test and a docs page.

## Scope

### In Scope
- One custom page at `/curriculum/coverage`, registered in `src/registry.js`, reached from the Learning menu next to "Curriculum". Not a card on the Reports page: a report is a card or a menu entry, never both (ADR-112).
- A read-only `CnDataMatrix`: goals as rows grouped under their domains, years as columns (one "All years" column when the framework has none), words in each cell (planned and assessed, planned, assessed, not covered), the deepest depth's label in brackets.
- Filters: framework and subject (all subjects, each subject, no subject). A `?framework=<id>` query preselects a framework.
- The gap list per subject and year, and a one-line summary of the selection's totals.
- Empty states for no framework and for a framework without coverage yet.

### Out of Scope
- Editing alignments from the matrix. The matrix is read-only; alignments are edited on the lesson, course, assignment or assessment.
- A Reports card (see above) and a dashboard widget.
- Per-learner attainment. That stays in the skills gap dashboard.

## Approach
The same shape as `CohortGradebookView`: a thin Vue component over OpenRegister's objects API, with every layout rule in pure functions in `src/utils/curriculumCoverage.js` so they are tested with `node --test`. The view reads `CompetencyFramework`, `Competency`, `CurriculumCoverage` and, for subject names, `Course`; it writes nothing.

## New Dependencies
None. `CnDataMatrix` is already registered.

## Impact
- New: `src/views/CurriculumCoverageMatrixView.vue`, `src/utils/curriculumCoverage.js`, `tests/unit-js/curriculumCoverage.test.mjs`, `docs/user-guide/user/09-curriculum-coverage.md`.
- `src/registry.js`: one import and entry. `src/manifest.d/learning.json`: one page and one menu entry.
- `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`: the view's strings.

## Cross-Project Dependencies
None. Stacked on `curriculum-coverage-rollup`, which is stacked on `goal-alignment-depth` and `competency-year-scope`.

## Risks

### Risk 1: The matrix is read as a record of learning
**Severity:** Medium. **Mitigation:** the intro line says it shows the plan, not what learners have mastered; cells say "planned" and "assessed", never "mastered".

### Risk 2: A large framework makes a long matrix
**Severity:** Low. **Mitigation:** the subject filter narrows it, and the gap list below it answers the main question without scrolling the grid.

## Rollback Strategy
Revert the diff. The page reads only; nothing is stored.

## Open Questions
None.

# Design: curriculum-coverage-matrix-view

## Architecture Overview
```
Learning menu > Curriculum coverage  ->  /curriculum/coverage  (type custom, component CurriculumCoverageMatrixView)
  CurriculumCoverageMatrixView.vue           thin: load, filter, render
    GET objects/learniq/competency-framework          framework picker
    GET objects/learniq/competency?frameworkId=        the goal tree
    GET objects/learniq/curriculum-coverage?frameworkId=  rows written by the rollup (change 3)
    GET objects/learniq/course/<id>                    subject names
    src/utils/curriculumCoverage.js           pure: coverageMatrix, gapList, subjectOptions, goalTree
      -> CnDataMatrix (readOnly) + gap list
```
It follows `CohortGradebookView`, the one other `CnDataMatrix` page: a thin component over the objects API with the
layout rules in pure, node-tested helpers. The view computes no coverage; it lays out rows the rollup keeps current.

## Decisions

### D1: Menu entry next to "Curriculum", no Reports card
The brief asks for the Curriculum menu. "Curriculum" is an entry (order 20) in the Learning group, so "Curriculum
coverage" sits right after it (order 21). ADR-112 forbids a report being both a card and an entry, so it gets no card
on the Reports page. Visible to instructor, coordinator, team lead, administration manager and admin, the roles that
map to the read groups of `CurriculumCoverage`.

### D2: Domain rows as headings, words in cells
`CnDataMatrix` has no row grouping and no cell slots. A domain becomes a read-only row with empty cells, and goals are
indented under it with non-breaking spaces. Cells carry words ("Planned and assessed", "Planned", "Assessed", "Not
covered") so status never depends on colour (WCAG 1.4.1).

### D3: Year columns come from the rows, a cell only where the goal applies
Columns are the year labels of the selected rows in natural order ("groep 2" before "groep 10"). A goal's status comes
from the selection's all-years row; its year membership from the year rows. A goal shows nothing in a year it does not
apply to, which keeps the rollup's year-independent status (change 3, D2) from reading as "covered in every year".

### D4: Gap list from the per-subject rows
Sections come from the `subject` and `none` rows: year rows when the framework has years, else the all-years rows, so a
goal is never listed twice for one subject. Empty sections are left out.

### D5: Everything testable lives outside the component
`src/utils/curriculumCoverage.js` takes translated labels as input and holds every rule; `tests/unit-js/
curriculumCoverage.test.mjs` pins them, plus the manifest and registry wiring.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Page and menu entry | Declarative manifest | Standard page registration |
| Goal × year matrix with domain headings and a gap list | Custom view (the one exception for this capability's coverage reading) | No declarative page type crosses a recursive goal tree with year columns; same exception class as `SkillsGapDashboard` and `CohortGradebookView` |
| Coverage numbers | Already derived by `curriculum-coverage-rollup` | Nothing computed here |

## Security Considerations
Read-only. It reads through OpenRegister's objects API with the user's own rights, so `CurriculumCoverage`'s staff-only
read rule applies unchanged; the menu entry is hidden from other roles. No personal data is shown.

## NL Design System
Nextcloud components and CSS variables only (`NcSelect` with `inputLabel`, `NcNoteCard`, `NcEmptyContent`,
`NcLoadingIcon`, `CnDataMatrix`); spacing from `--default-grid-baseline`, muted text from `--color-text-maxcontrast`. No
hard-coded colours.

## File Structure
```
src/views/CurriculumCoverageMatrixView.vue     new
src/utils/curriculumCoverage.js                new, pure
src/registry.js                                import + entry
src/manifest.d/learning.json                   page CurriculumCoverageMatrix + menu entry CurriculumCoverageMenu
tests/unit-js/curriculumCoverage.test.mjs      new
docs/user-guide/user/09-curriculum-coverage.md           new
l10n/en.json, l10n/nl.json, l10n/*.js           strings
```

## Seed Data
No schema changes. The page reads the `CurriculumCoverage` demo rows added by `curriculum-coverage-rollup` (three rows
for `groep 5`: all subjects, rekenen, no subject), so the demo shows a matrix and a gap list.

## Trade-offs
A matrix of words is wider than a colour grid. It is the accessible choice, and the subject filter plus the gap list
keep the page usable for large frameworks.

# Tasks: curriculum-coverage-matrix-view

## Implementation Tasks

### Task 1: Pure builders for the matrix and the gap list
- **spec_ref**: `openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked`, `#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year`
- **files**: `src/utils/curriculumCoverage.js`, `tests/unit-js/curriculumCoverage.test.mjs`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN coverage rows shaped as the rollup writes them WHEN built THEN rows, columns, cells, subject options and gap sections match the spec scenarios
- [x] Implement
- [x] Test

### Task 2: CurriculumCoverageMatrixView, registry, page and menu entry
- **spec_ref**: `#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked`
- **files**: `src/views/CurriculumCoverageMatrixView.vue`, `src/registry.js`, `src/manifest.d/learning.json`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN built THEN `/curriculum/coverage` renders `CurriculumCoverageMatrixView`, reached from the Learning menu right after Curriculum, and no Reports card routes to it
  - GIVEN `npm run check:manifest`, `check:menu-role-gates` and the registry coverage test WHEN run THEN they pass
- [x] Implement
- [x] Test

### Task 3: Strings and docs
- **spec_ref**: all requirements
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`, `docs/user-guide/user/09-curriculum-coverage.md`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN every new `t()` string and manifest label WHEN checked THEN English and Dutch values exist and `check:l10n-js` passes
  - GIVEN the docs page WHEN read THEN it explains planned, assessed, the gap list and how coverage fills in
- [x] Implement
- [x] Test

## Verification
- `openspec validate curriculum-coverage-matrix-view --strict` passes
- `node --test tests/unit-js/curriculumCoverage.test.mjs` and `npm run test:js-unit` (no new failures)
- `npx eslint` and `npx stylelint` on the new files; `npm run build`; `composer check:strict` once; hydra gates

## Quality checklist
- Node tests for the builders and the wiring (ADR-009). No PHP changes.
- Playwright: not run by this lane (lanes may not drive the shared instance); scenarios carry a reasoned `@e2e exclude`.
- Documentation (ADR-010): `docs/user-guide/user/09-curriculum-coverage.md`; screenshots follow when a browser pass is possible.
- i18n (ADR-005): English and Dutch for every string.

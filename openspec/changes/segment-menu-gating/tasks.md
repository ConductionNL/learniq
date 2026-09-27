# Tasks: segment-menu-gating

Stacked on `segment-wizard-choice` (#1028, itself on `segment-runtime-bridge` #1022): needs `runtime.workspace.segment` and the wizard's segment step.

## Implementation Tasks

### Task 1: Gate the company, MBO, HE and secondary-and-up entries (should, V1)
- **spec_ref**: `openspec/changes/segment-menu-gating/specs/nextcloud-app/spec.md#requirement-menus-follow-the-kind-of-organisation`
- **files**: `src/manifest.d/{compliance,dashboard,progress,work-placement,progress-decisions,assessment-board,learning,admissions}.json`, `tests/unit-js/segmentMenuGates.test.mjs`
- **acceptance_criteria**:
  - GIVEN segment po WHEN the menu is evaluated for an admin THEN the school shape shows and the gated entries hide
  - GIVEN each segment WHEN BPV, BSA and school advies are evaluated THEN they show exactly for the segments in the matrix
- [x] Implement
- [x] Test

### Task 2: Keep the company default whole, mechanically (must, V1)
- **spec_ref**: `openspec/changes/segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-company-default-keeps-every-menu`
- **files**: `tests/validate-menu-role-gates.js`, `tests/unit-js/segmentMenuGates.test.mjs`
- **acceptance_criteria**:
  - GIVEN segment corporate WHEN every entry is evaluated THEN every entry shows
  - GIVEN a gate without corporate, or with an unknown literal, WHEN the validator runs THEN it exits 1 naming the entry
- [x] Implement
- [x] Test

### Task 3: Wizard copy and docs (should, V1)
- **spec_ref**: `openspec/changes/segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-wizard-says-what-the-segment-does`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`), `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN the segment step WHEN read THEN it says the app shows the menus that fit, in en and nl (gate 102)
  - GIVEN the docs WHEN read THEN they carry the matrix and say a hidden menu is not an access control
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-menu-gating --strict` passes
- [x] Diff-scoped checks green (check:specs incl. menu-role-gates, node tests, gate 53/68/100/102/104/107 standalone)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/development` run once before push (inherited reds only; see the PR)

## Quality checklist
- Tests: `segmentMenuGates.test.mjs` (matrix with the library's own evaluator, plus negative validator runs), `setupSteps.test.mjs`.
- No PHP changes; PHPUnit covers nothing new here.
- i18n: the changed step body in en and nl.

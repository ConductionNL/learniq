# Tasks: company-segment-menu-gating

Cut from `origin/development` after `segment-menu-gating` (#1041) merged. Decision D26.

## Implementation Tasks

### Task 1: Publish the chosen segment (must, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default`
- **files**: `lib/Service/SegmentService.php`, `lib/Controller/PageController.php`, `src/utils/workspaceRuntime.js`, `src/main.js`, `tests/Unit/Service/SegmentServiceTest.php`, `tests/Unit/Controller/PageControllerTest.php`, `tests/unit-js/workspaceRuntime.test.mjs`
- **acceptance_criteria**:
  - GIVEN a row with `setBy` naming an existing user WHEN the page loads THEN `chosenSegment` is its code
  - GIVEN only the generated demo rows WHEN the page loads THEN `chosenSegment` is null and `segment` is corporate
  - GIVEN a failed read or an unknown value WHEN the page loads THEN `chosenSegment` is null
- [x] Implement
- [x] Test

### Task 2: Filter Reports cards by their visibleIf (must, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus`
- **files**: `src/utils/reportCardGates.js`, `src/main.js`, `tests/unit-js/reportCardGates.test.mjs`
- **acceptance_criteria**:
  - GIVEN a Reports card whose visibleIf fails WHEN the manifest is prepared THEN the card is gone and the other cards keep their order
  - GIVEN a card without visibleIf, or a page of another type WHEN the manifest is prepared THEN nothing changes
- [x] Implement
- [x] Test

### Task 3: Gate the school-only surfaces for a chosen company (must, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus`
- **files**: `src/manifest.json`, `src/manifest.d/{admissions,assessment-board,compliance,guardian-meetings,learning,progress,work-placement}.json`
- **acceptance_criteria**:
  - GIVEN chosen corporate WHEN the menu, the landing pages and Reports render for an admin THEN the eight school-only groups hide and everything else shows
  - GIVEN chosen training WHEN admissions and the exam board are evaluated THEN they show
- [x] Implement
- [x] Test

### Task 4: Move the segment gates to surfaces that render (must, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-segment-gates-sit-on-surfaces-that-render`
- **files**: `src/manifest.json`, `src/manifest.d/{assessment-board,compliance,progress,progress-decisions,work-placement}.json`
- **acceptance_criteria**:
  - GIVEN po WHEN the Progress landing renders THEN BPV, BSA, engagement and course evaluation cards hide
  - GIVEN vo WHEN Compliance renders THEN the group shows with exam board, accessibility and privacy cards and without the company cards
- [x] Implement
- [x] Test

### Task 5: Validator and matrix test over the merged menu (must, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-an-install-that-never-chose-keeps-every-menu`
- **files**: `tests/validate-menu-role-gates.js`, `tests/unit-js/segmentMenuGates.test.mjs`
- **acceptance_criteria**:
  - GIVEN never chose WHEN every surface is evaluated for an admin THEN every one shows
  - GIVEN a chosenSegment predicate other than notIn, an unknown code, or a segment gate on a relocated group WHEN the validator runs THEN it exits 1 naming the entry
- [x] Implement
- [x] Test

### Task 6: Docs (should, V1)
- **spec_ref**: `openspec/changes/company-segment-menu-gating/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus`
- **files**: `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN the docs WHEN read THEN they list what a chosen company hides and say an install that never chose keeps everything
- [x] Implement

## Verification
- [x] All tasks checked off
- [x] `openspec validate company-segment-menu-gating --strict` passes
- [x] Diff-scoped checks green (php -l, phpcs, phpunit on the two touched classes, eslint on touched JS, node tests, check:specs)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates run once before push (inherited reds named in the PR)

## Quality checklist
- Tests: SegmentServiceTest and PageControllerTest for the value, workspaceRuntime and reportCardGates node tests, the matrix over the merged menu with negative validator runs.
- i18n: no new strings.
- Seed data: no schema change.

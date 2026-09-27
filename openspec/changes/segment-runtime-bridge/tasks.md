# Tasks: segment-runtime-bridge

## Implementation Tasks

### Task 1: Widen the segment enum and label it (must, MVP)
- **spec_ref**: `openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-learniqsettings-knows-six-organisation-kinds`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`), `tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN `LearniqSettings.segment` is read THEN its enum is `po, vo, mbo, he, corporate, training` with an `x-enum-labels` entry per value
  - GIVEN the catalogues WHEN each label is looked up THEN en and nl both carry it
  - GIVEN the schema version and `info.version` WHEN compared to development THEN both are bumped
  - GIVEN the demo register WHEN its `LearniqSettings` rows are read THEN every row is `corporate`
- [x] Implement
- [x] Test

### Task 2: Resolve the current segment on the server (must, MVP)
- **spec_ref**: `openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment`
- **files**: `lib/Service/SegmentService.php`, `tests/Unit/Service/SegmentServiceTest.php`
- **acceptance_criteria**:
  - GIVEN no row WHEN resolved THEN `corporate`
  - GIVEN several rows WHEN resolved THEN the newest valid one wins
  - GIVEN only unknown values WHEN resolved THEN `corporate`
  - GIVEN the read throws WHEN resolved THEN `corporate` and a log line
  - GIVEN `SegmentService::SEGMENTS` WHEN compared to the schema enum THEN they are equal
- [x] Implement
- [x] Test

### Task 3: Provide the segment as initial state (must, MVP)
- **spec_ref**: `openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime`
- **files**: `lib/Controller/PageController.php`, `tests/Unit/Controller/PageControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a signed-in user WHEN `index()` runs THEN `provideInitialState('segment', <current segment>)` is called
  - GIVEN an anonymous request WHEN `index()` runs THEN no segment state is provided
- [x] Implement
- [x] Test

### Task 4: Publish runtime.workspace.segment in the browser (must, MVP)
- **spec_ref**: `openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime`
- **files**: `src/utils/workspaceRuntime.js`, `src/main.js`, `tests/unit-js/workspaceRuntime.test.mjs`
- **acceptance_criteria**:
  - GIVEN `loadState` returns `po` WHEN the runtime is built THEN `runtime.workspace.segment` is `po`
  - GIVEN a missing or unknown value WHEN the runtime is built THEN it is `corporate`
  - GIVEN the JS segment list WHEN compared to the schema enum THEN they are equal
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-runtime-bridge --strict` passes
- [x] Diff-scoped checks green (php -l, phpcs, phpstan, phpunit filter, eslint, node test, check:specs, check:schema-l10n)
- [x] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/development` run once before push (inherited reds only; see the PR)

## Quality checklist
- New PHP covered by `SegmentServiceTest` and `PageControllerTest`; new JS by `workspaceRuntime.test.mjs`.
- No new API endpoint (Newman N/A); no new rendered UI beyond enum labels (Playwright N/A: the only visible change is a dropdown label, covered by the register test and the catalogue check).
- Docs: N/A for this change; the segment becomes user-visible in `segment-wizard-choice`, which documents it.
- i18n: six label keys in en and nl.

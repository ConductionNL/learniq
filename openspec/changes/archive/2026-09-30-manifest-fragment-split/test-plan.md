# Test Plan: manifest-fragment-split

## Test Cases

### TC-1: Effective manifest diff is empty
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **type**: regression
- **persona**: n/a (build-time check)
- **preconditions**: two working trees — `pre` (current git ref, monolithic `manifest.json` + 2 legacy fragments) and `post` (this change applied, 14 fragments + skeleton `manifest.json`)
- **steps**: write a small Node script that `require()`s `@conduction/nextcloud-vue`'s `buildManifest`, loads `manifest.json` + every `manifest.d/*.json` (via `fs.readdirSync`, mirroring `require.context`'s sorted order) + `menu-layout.json` for each tree, calls `buildManifest(base, fragments, menuLayout)`, and deep-compares the two resulting objects (`JSON.stringify` after a stable key-sort for object comparison, but array order preserved and compared as-is)
- **expected result**: the diff is empty — identical `pages[]` (every id/route/type/component/config) and identical `menu[]` tree (every id, every field, every `children[]` array in the same order at every depth)
- **test command**: `/test-regression` (run the comparison script as part of the regression pass; not a Playwright browser test — see spec scenario's `@e2e exclude`)

### TC-2: Live nav renders identically pre/post
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **type**: functional
- **persona**: n/a (admin smoke check)
- **preconditions**: Scholiq deployed on the shared dev instance (localhost:8080) with this change applied
- **steps**: log in as admin, open the left nav, expand every top-level group, screenshot the fully-expanded tree; compare against a screenshot taken before the change
- **expected result**: identical set of top-level groups in the same order, identical children under each, identical footer (Documentation, Features & roadmap) and settings-foldout contents (xAPI statements, School-year rollover)
- **test command**: `/test-functional`

### TC-3: `data-exchange.json` deletion is a clean single-file removal (dry run)
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **type**: regression
- **persona**: n/a
- **preconditions**: post-split tree
- **steps**: in a scratch copy, delete `src/manifest.d/data-exchange.json` only, rerun `buildManifest()`, inspect the resulting manifest
- **expected result**: `GroupDataExchange` and every page it owned are absent from the effective manifest; every other group, page, and route is unaffected; no dangling reference (relocation/removal/settingsSection target) in `menu-layout.json` points at a now-missing id — because `menu-layout.json` never referenced `GroupDataExchange` or its children to begin with (confirmed against the current file)
- **test command**: `/test-regression`

### TC-4: Every existing e2e deep link still resolves
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **type**: regression
- **persona**: n/a
- **preconditions**: `tests/e2e/pages.spec.ts`'s existing route table, post-split deployment
- **steps**: run the existing Gate-19 route-smoke suite unmodified against the post-split build
- **expected result**: 100% pass, zero new failures — no route table edit is needed because no route changed
- **test command**: `/test-regression`

## Coverage Summary

- REQ-001 (fragments own disjoint subtrees): covered by TC-3 (isolation proof via a leaving-module deletion dry run) and by code review against the Decision 3 mapping table in design.md.
- REQ-002 (no behaviour change): covered by TC-1 (computed diff — the load-bearing check), TC-2 (visual confirmation), TC-4 (existing e2e suite as a regression net).

## Out of Scope

- New Playwright specs — none are added; the existing Gate-19 route table is reused unmodified (TC-4), which is itself evidence nothing needed to change.
- Performance testing — file-count growth from 2 to 14 small JSON files has no meaningful webpack or runtime-merge cost; not measured.

## Scope

Registers 8 previously-unregistered shared `@conduction/nextcloud-vue` components (`CnDataMatrix`, `CnWizardDialog`, `CnRichSubmitDialog`, `CnExportWizard`, `CnSignatureCapture`, `CnTimelineView`, `CnStructuredDocReview`, `CnRelationshipGraph`) in `src/registry.js` so the 14 `type: "custom"` manifest pages that already name them by their exact export string actually mount, instead of `CnPageRenderer.resolveCustomComponent()` finding nothing and rendering an empty page body. Adds a regression test that diffs every declared custom page's `component` string against the registry's keys, so a future drift fails a test instead of shipping a blank page.

## Evidence

`learniq-defect-triage.md` (round-1 competitor/defect corpus), entry 1: verified live in the browser that `CohortTimetable` renders "This page is empty" (the M1 baseline capture's own header), and statically confirmed the same root cause across 13 more pages by grepping the manifest for the 8 component names and the registry for their absence. All 8 components are genuine exports of the installed `@conduction/nextcloud-vue` (verified: `node_modules/@conduction/nextcloud-vue/dist/esm/index.js` lines 42/51/87/88/91/92/97/117).

## M1 rows

Recovers 3 rows outright per the triage's summary table: `3.10` (bulk enrol by group/role/department/CSV), `7.1` (gradebook per class/subject), `16.4` (audit pack export). Is a prerequisite (not sufficient alone) for 7 more rows that cite a second, independent gap: `3.5`, `4.5`, `5.4`, `6.3`, `8.3`, `9.10`, `11.1`.

## What was verified (exit codes)

- `node --test tests/unit-js/registryComponentCoverage.test.mjs` — exit 0 (3/3 pass)
- `npm run test:js-unit` (full suite) — exit 0; 3 pre-existing failures unrelated to this change, confirmed inherited via `git stash` against the unmodified baseline (same 3 failures present without this diff)
- `npx eslint src/registry.js tests/unit-js/registryComponentCoverage.test.mjs` — exit 0
- `npm run check:manifest` — exit 0
- `npm run build` (webpack, via the lane's resource semaphore) — exit 0 (pre-existing bundle-size warnings only)
- `npm run lint` — exit 0 (19 pre-existing warnings, 0 errors, none in touched files)
- `npm run format` (prettier --check) — exit 0
- `npm run test:l10n` — script does not exist in this repo; skipped
- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via semaphore) — `ALL CHECKS PASSED`: lint/phpcs/phpmd/psalm/phpstan all 0 errors, full PHPUnit 1074 tests / 4790 assertions / 5 skipped. (641 psalm info-level findings are pre-existing/inherited, not on lines this PR touches.)
- `bash hydra/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development` — 1 gate failed: `gate-112 newman-reach` ("0 of 90 committed request(s) run") — this is a pre-existing Postman/Newman CI-wiring gap across the whole app, unrelated to this diff (nothing here touches a Postman collection); every gate whose scope includes a file this PR touches (`registry.js`, the new test file) passed or was correctly not-applicable. `gate-68 duplicate-index-pages` did not run (broken checker exit 1, unrelated to this diff).

## Inherited findings

phpcs: several pre-existing `@spec` PHPDoc warnings on unrelated files (0 errors). Psalm: 641 pre-existing info-level findings. Hydra gate-112 (newman-reach) and the gate-68 checker failure are both pre-existing/whole-app issues, not introduced by this diff. None of these are on lines this PR touches; none were fixed here per the fleet's inherited-debt policy.

## Note on the test runner

The task brief that seeded this change asked for "a vitest." This repo has no vitest binary or config anywhere; its actual JS unit-test convention is `node --test tests/unit-js/*.test.mjs` (see `tests/unit-js/connectionRegistry.test.mjs`). The new guard test follows that existing convention and is functionally identical to what was asked: it diffs every `type: "custom"` page's `component` against the registry's `kind: "page"` keys and fails on a miss. Documented as Decision 2 in `openspec/changes/registry-component-fix/design.md`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

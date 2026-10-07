# Tasks: wizard-drops-the-removal-step

- [x] 1.1 Drop the `remove-example-set` step from `src/manifest.json`.
- [x] 1.2 Remove the per-set step expansion (`src/utils/exampleSetSteps.js`, its call in `src/main.js`, `tests/unit-js/exampleSetSteps.test.mjs`).
- [x] 1.3 `tests/unit-js/setupSteps.test.mjs` pins four steps and no removal step; fails on the old manifest.
- [x] 1.4 `docs/installation.md` names the `occ` command for removal.
- [x] 1.5 Manifest validates against `app-manifest-v2.schema.json`.

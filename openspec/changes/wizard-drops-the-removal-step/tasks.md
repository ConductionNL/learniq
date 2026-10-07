# Tasks: wizard-drops-the-removal-step

> Archive pass 2026-10-07: code done; not archived: `openspec validate --strict` fails, the MODIFIED example-sets requirement omits three scenarios the main spec still has (Removing the company set, An OpenRegister without the method, Nothing was loaded); deciding whether they stay is a spec call.

- [x] 1.1 Drop the `remove-example-set` step from `src/manifest.json`.
- [x] 1.2 Remove the per-set step expansion (`src/utils/exampleSetSteps.js`, its call in `src/main.js`, `tests/unit-js/exampleSetSteps.test.mjs`).
- [x] 1.3 `tests/unit-js/setupSteps.test.mjs` pins four steps and no removal step; fails on the old manifest.
- [x] 1.4 `docs/installation.md` names the admin page and the `occ` command for removal.
- [x] 2.1 `GET /api/setup/example-sets` (admin-only) returns the loaded sets; `SetupControllerTest` covers a loaded and an empty list.
- [x] 2.2 `src/views/settings/ExampleDataSettingsSection.vue` on the admin page, with `src/utils/exampleSetRemoval.js`: confirm, post `remove-example-set-<id>`, show the result, read the list again.
- [x] 2.3 en and nl strings; `tests/unit-js/exampleSetRemoval.test.mjs` (fails on the old code: the module does not exist).
- [x] 1.5 Manifest validates against `app-manifest-v2.schema.json`.

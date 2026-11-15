# Tasks: merge a duplicate learner account from the learner's page

## 1. Register

- [x] 1.1 `lib/Settings/learniq_register.json` `learner-profile` `merge`: add `label` "Merge into another account" and `inputs: [{field: "mergedInto", required: true}]`. Give `mergedInto` a reference to `learner-profile` so a picker can resolve it. Verify: validate a real merge payload against the fragment.
  - Done: `label` and `inputs` on `merge`; `mergedInto` already carried `$ref: LearnerProfile` with `format: uuid`. `learner-profile` moves to 0.3.9. Test: `tests/Unit/Settings/LearnerMergeTransitionRegisterTest.php` validates a merge payload with Opis and refuses a survivor that is not a uuid.

## 2. Page

- [x] 2.1 `src/manifest.d/people.json` LearnerProfileDetail: `lifecycleActions: {field: "lifecycle", transitions: [{from: "active", to: "merged", action: "merge", label, confirm, variant: "error", inputs: [{field: "mergedInto", required: true}]}]}`; rewrite the `_note`. Verify: `npm run check:manifest`.
  Built with a manifest `inputs` hint for the picker (`filter: {lifecycle: active}`, `excludeSelf`, `labelField: fullName`); `npm run check:manifest` passes.
  - Not switched on yet. Design D2 says the survivor is picked and never typed. Until the picker in 2.2 lands, `CnTransitionInputDialog` would show `mergedInto` as a text field for a uuid, so the action waits for 2.2.
- [x] 2.2 The picker: needs the nextcloud-vue reference picker in `CnTransitionInputDialog` (filter `lifecycle: active`, exclude the current uuid). Bump `@conduction/nextcloud-vue` once it lands. Leave open until then.
  `CnTransitionInputDialog` in `@conduction/nextcloud-vue` 2.180.0, which the app already requires, draws a reference picker with `picker.filter` and `picker.excludeSelf`; no bump needed.
- [x] 2.3 `lprof-data`: show `mergedInto` as a link to `LearnerProfileDetail` when set.
  - Done: `lprof-facts` shows "Merged into" (the survivor's `fullName`, linked to `LearnerProfileDetail`) when `lifecycle` is `merged`. That is exactly when the guard requires `mergedInto`. There is no widget named `lprof-data`; the facts widget is the data block. `npm run check:manifest` passes.
- [x] 2.4 English and Dutch strings (design). Verify: `npm run check:l10n`.
  - Done: "Merged into" / "Samengevoegd met" and "Merge into another account" / "Samenvoegen met ander account" in `l10n/en.json` and `nl.json`. `en.js` and `nl.js` were regenerated with `l10n:build`. `check:l10n` is at its baseline, and `check:schema-l10n` is under it. The confirm and picker strings come with 2.1/2.2.

## 3. Tests and close out

- [x] 3.1 PHPUnit: `LearnerMergeGuardTest` keeps passing.
  `--filter LearnerMerge`: 14 tests pass.
  - No `LearnerMergeGuardTest` exists in `tests/`. The guard's partner `LearnerMergeHandlerTest` passes. A guard test is still to be written.
- [ ] 3.2 Playwright `tests/e2e/learner-merge.spec.ts`: as `hr` merge two seeded duplicates; as `hr` try a pair with one shared open course and read the reason; as an instructor see no action. Tag the scenarios with `@e2e`.
- [ ] 3.3 Set row `gov-merge-duplicate-accounts` to `built`, `learniq: yes`, `reachedOn: "/learner-profiles/:id > Actions > Merge into another account"`; archive this change into `openspec/specs/learner-account-merge`.

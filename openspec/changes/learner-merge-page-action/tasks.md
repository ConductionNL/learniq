# Tasks: merge a duplicate learner account from the learner's page

## 1. Register

- [ ] 1.1 `lib/Settings/learniq_register.json` `learner-profile` `merge`: add `label` "Merge into another account" and `inputs: [{field: "mergedInto", required: true}]`. Give `mergedInto` a reference to `learner-profile` so a picker can resolve it. Verify: validate a real merge payload against the fragment.

## 2. Page

- [ ] 2.1 `src/manifest.d/people.json` LearnerProfileDetail: `lifecycleActions: {field: "lifecycle", transitions: [{from: "active", to: "merged", action: "merge", label, confirm, variant: "error", inputs: [{field: "mergedInto", required: true}]}]}`; rewrite the `_note`. Verify: `npm run check:manifest`.
- [ ] 2.2 The picker: needs the nextcloud-vue reference picker in `CnTransitionInputDialog` (filter `lifecycle: active`, exclude the current uuid). Bump `@conduction/nextcloud-vue` once it lands. Leave open until then.
- [ ] 2.3 `lprof-data`: show `mergedInto` as a link to `LearnerProfileDetail` when set.
- [ ] 2.4 English and Dutch strings (design). Verify: `npm run check:l10n`.

## 3. Tests and close out

- [ ] 3.1 PHPUnit: `LearnerMergeGuardTest` keeps passing.
- [ ] 3.2 Playwright `tests/e2e/learner-merge.spec.ts`: as `hr` merge two seeded duplicates; as `hr` try a pair with one shared open course and read the reason; as an instructor see no action. Tag the scenarios with `@e2e`.
- [ ] 3.3 Set row `gov-merge-duplicate-accounts` to `built`, `learniq: yes`, `reachedOn: "/learner-profiles/:id > Actions > Merge into another account"`; archive this change into `openspec/specs/learner-account-merge`.

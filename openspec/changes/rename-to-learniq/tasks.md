# Tasks: rename-to-learniq

Tasks are grouped by **boundary**, not by file — this change touches 479 files (whole tracked tree
excluding `openspec/` — see proposal.md's "Footprint numbers" and design.md's "Measured footprint"),
and a file-by-file
breakdown would blow past any reviewable size. Each boundary lands as its own commit(s) and carries
its own verification, per design.md's enumeration: a green build proves nothing about whether a
boundary resolves at runtime.

## 1. Namespace, autoload, and lint config

### Task 1: Rename the PHP namespace and every tool config that names it
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **files**: every `lib/**/*.php` and `tests/**/*.php` `namespace`/`use` declaration (361 + dependents), `composer.json`, `psalm.xml`, `phpstan.neon`, `phpcs.xml`
- **acceptance_criteria**:
  - GIVEN the renamed codebase WHEN `composer dump-autoload` runs THEN it resolves every class under `OCA\Learniq\` with zero "class not found" warnings
  - GIVEN the renamed codebase WHEN `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) runs THEN it passes with no new findings introduced by the rename itself
- [ ] Implement + verify (`composer dump-autoload && composer check:strict` clean)

## 2. App id, routes, install path, navigation

### Task 2: Rename the app id everywhere it is declared, not derived
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **files**: `appinfo/info.xml` (`<id>`, `<namespace>`, `scholiq.page.index` → `learniq.page.index`), `lib/AppInfo/Application.php` (`APP_ID`), `package.json`
- **acceptance_criteria**:
  - GIVEN the app mounted at `custom_apps/learniq` WHEN Nextcloud loads the app list THEN it registers under id `learniq` and the URL prefix is `/apps/learniq/`
  - GIVEN the app's navigation entry WHEN a user clicks it THEN it routes via `learniq.page.index`, not a leftover `scholiq.page.index` reference
- [ ] Implement + verify (fresh mount at `custom_apps/learniq`, nav entry clicks through)

## 3. Register-slug migration (data)

### Task 3: Ship the register-slug repair step and update every literal call site together
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration`
- **files**: `lib/Repair/RenameRegisterSlug.php` (new), `appinfo/info.xml` (`<post-migration>`), all 117 literal `'scholiq'` register-slug call sites across `lib/Controller/`, `lib/Lifecycle/`, `lib/BackgroundJob/`, `lib/Analytics/`, `lib/Engagement/`, `lib/Grading/`, `lib/CourseEvaluation/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the repair step runs THEN the register's slug is `learniq` and zero shard-table rows are touched (row counts identical before/after)
  - GIVEN the repair step has already run WHEN it runs a second time THEN it makes no further change and logs zero renames (idempotent)
  - GIVEN two registers where one already has slug `learniq` WHEN the step encounters the collision THEN it logs a warning and skips rather than merging or overwriting
- [ ] Implement (repair step + all 117 call sites in the same deploy, per design.md's ordering requirement)
- [ ] Verify against seeded pre-existing objects (migration.md's Validation section — fetch a known pre-migration object id under `register: 'learniq'`, confirm byte-identical property values)

## 4. IAppConfig key migration (data)

### Task 4: Ship the config-key migration repair step, preserving the ADR-023 action matrix
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-stored-per-install-configuration-survives-the-identity-rename`
- **files**: `lib/Repair/MigrateAppConfigKeys.php` (new), `appinfo/info.xml` (`<post-migration>`), every `IAppConfig::getValueString('scholiq', ...)` / `setValueString('scholiq', ...)` call site → `'learniq'`
- **acceptance_criteria**:
  - GIVEN an admin has customized `scholiq.actions` away from the all-admin default WHEN the app upgrades THEN `learniq.actions` reads the same customized mapping, not the seed default
  - GIVEN the migration has already run WHEN it runs again THEN it does not overwrite an already-migrated `learniq.*` value
- [ ] Implement (repair step + all `IAppConfig` call-site renames)
- [ ] Verify action-authorization matrix survives (upgrade an install with a customized `scholiq.actions`, confirm `occ config:app:get learniq actions` matches)

## 5. Manifest and register JSON

### Task 5: Rename `scholiq_register.json` and update every slug reference inside it and in `manifest.json`
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **files**: `lib/Settings/scholiq_register.json` → `lib/Settings/learniq_register.json` (`x-openregister.app`, nested schema `slug`), `src/manifest.json` (5 `deepLinks[].registerSlug` entries)
- **acceptance_criteria**:
  - GIVEN the renamed register JSON WHEN the register sync runs THEN every schema declared in it registers under the `learniq` register
  - GIVEN a page carrying a `deepLinks[].registerSlug` entry WHEN a user follows that deep link THEN it resolves an object under the `learniq` register, not a 404
- [ ] Implement + verify (register sync succeeds, all 5 deep links resolve)

## 6. Filenames and Vue components

### Task 6: Rename all 14 files carrying the name in the filename and every reference to them
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **files**: `lib/Service/CoursePackage/ScholiqJsonCourseImporter.php`, 7× `src/views/Scholiq*.vue`, `tests/Unit/ScholiqTest.php`, `tests/integration/scholiq.postman_collection.json`, `tests/wedge-scaffolds/scholiq-wedge.postman_collection.json` (`lib/Mcp/ScholiqToolProvider.php` + its test are Task 7), plus every `import`/route definition referencing the old filenames
- **acceptance_criteria**:
  - GIVEN the renamed Vue files WHEN the frontend build runs (`npm run build`) THEN it succeeds with zero unresolved-import errors
  - GIVEN the renamed test files WHEN `composer test` runs THEN every renamed test is discovered and passes
- [ ] Implement + verify (`npm run build` and `composer test` both clean)

## 7. MCP tool provider

### Task 7: Rename the MCP tool provider, or confirm it is already gone
- **spec_ref**: `openspec/changes/rename-to-learniq/design.md#sequencing-with-in-flight-changes`
- **files**: `lib/Mcp/ScholiqToolProvider.php` → `lib/Mcp/LearniqToolProvider.php` (if still present), `tests/Unit/Mcp/ScholiqToolProviderTest.php` → `LearniqToolProviderTest.php`
- **acceptance_criteria**:
  - GIVEN `scholiq-mcp-adoption` has NOT yet merged WHEN this task runs THEN the provider file and its test are renamed and the provider id returns `'learniq'`
  - GIVEN `scholiq-mcp-adoption` HAS already merged (file deleted) WHEN this task runs THEN it confirms the derived `x-openregister-mcp` tool-name prefix already reads `learniq.{schema}.{verb}` (falls out of Task 2's app-id rename) and makes no further change
- [ ] Implement + verify (either branch of the above, confirmed against the actual state of `scholiq-mcp-adoption` at merge time)

## 8. l10n

### Task 8: Rename English-source keys naming the product, then substitute the product name across all 37 locales
- **spec_ref**: `openspec/changes/rename-to-learniq/design.md#l10n-decision`
- **files**: `l10n/en.json` (key rename for the small subset whose English key text mentions `Scholiq`), all 37 `l10n/*.json` (value substitution)
- **acceptance_criteria**:
  - GIVEN the English-source keys mentioning the product name WHEN renamed THEN every locale file has a correspondingly re-translated value (not a blind substitution) for those specific keys
  - GIVEN every other existing translated value containing the literal word `Scholiq`/`scholiq` WHEN substituted THEN a diff against the pre-change file shows only the product-name token changed, nothing else in the surrounding sentence
- [ ] Implement + verify (diff review across all 37 locale files per test-plan.md TC-10)

## 9. Product framing

### Task 9: Rewrite `appinfo/info.xml` summary/description (en + nl) to be audience-neutral and factual
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-product-framing-is-factual-and-audience-neutral-not-education-exclusive`
- **files**: `appinfo/info.xml` (`<summary lang="en">`, `<summary lang="nl">`, `<description lang="en">`, `<description lang="nl">`)
- **acceptance_criteria**:
  - GIVEN the rewritten English summary WHEN read THEN it does not describe the app as education-only and does not claim a capability absent from `docs/FEATURES.md`'s shipped tier
  - GIVEN the rewritten Dutch summary WHEN read THEN it describes the same three audiences (school, training provider, company) as the English summary, not a narrower translation
- [ ] Implement + verify (App Store listing preview renders both locales correctly)

## 10. CI and coverage

### Task 10: Update CI workflow paths and re-baseline coverage under the new namespace
- **spec_ref**: `openspec/changes/rename-to-learniq/proposal.md#impact`
- **files**: `.github/workflows/spec-validation.yml`, `.github/workflows/release.yml`, `.github/workflows/openspec-sync.yml`, `.github/workflows/documentation.yml`, `.github/workflows/code-quality.yml`, `.github/workflows/issue-triage.yml`
- **acceptance_criteria**:
  - GIVEN the renamed repo WHEN CI runs on the PR branch THEN every workflow's path filters and coverage-baseline references resolve under `OCA\Learniq` / `learniq` and none silently no-ops on a stale `scholiq` path
- [ ] Implement + verify (a CI run on the actual PR branch is green, not merely "would be green" by inspection)

## 11. Fresh-install verification

### Task 11: Verify a fresh install on a clean instance end-to-end
- **spec_ref**: `openspec/changes/rename-to-learniq/test-plan.md#tc-2-fresh-install-completes-and-the-register-imports-under-the-new-slug`
- **files**: none (verification-only task)
- **acceptance_criteria**:
  - GIVEN a clean Nextcloud instance with no prior `scholiq` install WHEN `learniq` is installed THEN install completes with zero errors in `nextcloud.log`, the register imports under slug `learniq`, and the app's start screen renders
- [ ] Verify (clean-instance install per test-plan.md TC-1/TC-2)

## 12. Route-reachability verification (ADR-029)

### Task 12: Run the full e2e suite and the ADR-029 route-reachability gate against the renamed routes
- **spec_ref**: `openspec/changes/rename-to-learniq/test-plan.md#tc-6-every-e2e-route-target-still-answers-after-the-route-prefix-change`
- **files**: e2e test targets updated from `/apps/scholiq/...` to `/apps/learniq/...`
- **acceptance_criteria**:
  - GIVEN the full Playwright e2e suite retargeted to `/apps/learniq/...` WHEN run against the renamed, freshly-installed app THEN every previously-passing test still passes and `hydra-gate-route-reachability` reports zero unrouted or wrong-binding methods
- [ ] Verify (e2e suite green + route-reachability gate clean per test-plan.md TC-6/TC-7)

## 13. Cross-app coordination

### Task 13: Open both cross-app follow-ups referencing this change's contract, as a precondition of archiving
- **spec_ref**: `openspec/changes/rename-to-learniq/contract.md#breaking-change-policy`
- **files**: none in this repo (tracking issues in `ConductionNL/hermiq` and `ConductionNL/pipelinq`)
- **acceptance_criteria**:
  - GIVEN this change has merged WHEN the hermiq follow-up issue is filed THEN it links `contract.md`, names the exact constants to update (`SCHOLIQ_APP_ID`, `SCHOLIQ_REGISTER` → `learniq` values, optionally renamed), and states the graceful "unavailable" degradation behavior in the interim
  - GIVEN this change has merged WHEN the pipelinq follow-up issue is filed THEN it links `contract.md`, names the exact constant to update (`DOWNSTREAM_SYSTEMS`'s `'scholiq'` entry → `'learniq'`), and flags that the interim failure mode is **silent** (no log, no error) rather than graceful, so it should be prioritized over the hermiq follow-up despite being the smaller code change
  - Both issues MUST be filed — not necessarily merged — before this change is archived; archiving without filing either would leave the cross-app breakage undiscoverable, since neither failure throws, error-logs, or fails a CI gate on the consumer side
- [ ] Verify hermiq issue filed and linked
- [ ] Verify pipelinq issue filed and linked

## 14. Repo rename and App Store republish (last)

### Task 14: Rename the GitHub repo and republish under the new App Store id, after every prior boundary is verified
- **spec_ref**: `openspec/changes/rename-to-learniq/proposal.md#rollback-strategy`
- **files**: none in-repo (GitHub repo settings, Nextcloud App Store listing)
- **acceptance_criteria**:
  - GIVEN every task above (1–12) is verified GREEN WHEN the repo is renamed `ConductionNL/scholiq` → `ConductionNL/learniq` THEN GitHub's automatic redirect from the old URL is confirmed working
  - GIVEN the App Store id changes WHEN the new listing is published THEN it is treated as a republish (new listing), not an in-place update, per design.md, and the old listing links to the new one
- [ ] Verify (redirect confirmed, new App Store listing live, old listing cross-links)

## Verification

- Every task above is checked off and its acceptance criteria independently confirmed, not just implemented.
- `openspec validate` passes for this change.
- Manual review confirms no remaining runtime (non-comment, non-archive) reference to `scholiq`/`Scholiq` under `lib/`, `src/`, `appinfo/`, `composer.json`, `package.json`.
- Code review against every ADDED requirement in `specs/app-metadata/spec.md`.
- **Archive gate**: this change is not archived until Task 13's two tracked issues (hermiq, pipelinq) exist and are linked from this change's record — per `contract.md`'s Breaking Change Policy, both cross-app couplings fail quiet on the consumer side, so an unfiled follow-up is indistinguishable from a forgotten one until someone notices the symptom weeks later.

## Tests (company-wide ADR-009)

- PHPUnit unit tests for `RenameRegisterSlug` and `MigrateAppConfigKeys` (idempotency, collision-guard, no-op-on-empty-source), plus every renamed test file passing under its new name.
- Newman/Postman collections renamed and passing (`learniq.postman_collection.json`, `learniq-wedge.postman_collection.json`).
- Playwright e2e suite retargeted and passing per Task 12.
- All tests pass: `composer test`, `newman run`, `npm run test` (frontend), full e2e run.

## Documentation (company-wide ADR-010)

- `docs/ARCHITECTURE.md` and `docs/FEATURES.md` updated to name the app `Learniq`, not `Scholiq`.
- App Store listing screenshot(s) refreshed if any visible chrome shows the old name.

## i18n (company-wide ADR-005)

- Both `nl_NL` and `en_US` product-framing strings updated (Task 9).
- All 37 existing locale files retain their translations; only the product-name token changes (Task 8).

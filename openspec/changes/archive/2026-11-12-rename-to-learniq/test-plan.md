# Test Plan: rename-to-learniq

## Test Cases

### TC-1: Every identity boundary agrees on a fresh install
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **type**: functional
- **persona**: n/a
- **preconditions**: a clean Nextcloud instance with no prior `scholiq` install
- **steps**: install the renamed app from `custom_apps/learniq`; open the admin apps page; open the app
  itself; inspect `appinfo/info.xml`, `composer.json`, `src/manifest.json`, `lib/Settings/learniq_register.json`
- **expected result**: app id, namespace, register slug, manifest `deepLinks[].registerSlug`, MCP
  provider id, and navigation route id all read `learniq`; none reads `scholiq`
- **test command**: `/test-functional`

### TC-2: Fresh install completes and the register imports under the new slug
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin — installs and configures apps)
- **preconditions**: clean instance, OpenRegister installed
- **steps**: install `learniq`; open Settings → Administration → Learniq; trigger register
  initialization (or observe `<install>` repair steps running)
- **expected result**: `occ` / admin UI shows a register with slug `learniq` and every schema from
  `lib/Settings/learniq_register.json` present; no error in `nextcloud.log`
- **test command**: `/test-functional`

### TC-3: Pre-existing objects resolve after the register-slug migration
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration`
- **type**: api
- **persona**: n/a
- **preconditions**: an install running the pre-rename app version with at least 5 seeded objects
  across 3+ schemas (Course, Enrolment, Attestation) created under the `scholiq` register slug
- **steps**: upgrade the app to this change's version; let `<post-migration>` repair steps run; call
  `ObjectService::find()` (via the OpenRegister REST API) for each seeded object id, passing
  `register: 'learniq'`
- **expected result**: every object returns with identical property values, owner, and relations to
  its pre-migration state; a lookup with `register: 'scholiq'` returns not-found
- **test command**: `/test-api`

### TC-4: Register-slug migration is idempotent
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration`
- **type**: regression
- **persona**: n/a
- **preconditions**: an install where the migration has already run once
- **steps**: trigger `<post-migration>` repair steps a second time (`occ upgrade` re-run or
  `occ maintenance:repair`)
- **expected result**: log output reports zero additional renames/migrations; no error; register slug
  and object data unchanged
- **test command**: `/test-api`

### TC-5: Admin-customized action-authorization matrix survives the config-key migration
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-stored-per-install-configuration-survives-the-identity-rename`
- **type**: security
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: pre-rename install where an admin has broadened `scholiq.actions` (e.g. mapped
  `course.publish` to a non-admin group)
- **steps**: upgrade the app; open the action-authorization admin panel
- **expected result**: the panel shows the admin's customized mapping, not the all-admin default; a
  non-admin user in the mapped group can invoke the action; `occ config:app:get learniq actions`
  matches the pre-upgrade `occ config:app:get scholiq actions` value
- **test command**: `/test-persona-noor`

### TC-6: Every e2e route target still answers after the route-prefix change
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **type**: regression
- **persona**: n/a
- **preconditions**: the app's existing Playwright e2e suite, updated to target `/apps/learniq/...`
  instead of `/apps/scholiq/...`
- **steps**: run the full e2e suite against the renamed, freshly-installed app
- **expected result**: every test that previously passed against `/apps/scholiq/...` passes against
  `/apps/learniq/...`; zero tests still reference the old prefix (`grep -r "apps/scholiq" tests/` in
  CI returns nothing)
- **test command**: `/test-regression`

### TC-7: Deep links resolve for every `manifest.json` page
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-the-apps-identity-is-a-single-value-repeated-at-every-boundary-not-independently-chosen-per-file`
- **type**: functional
- **persona**: Sem (Young Digital Native — bookmarks and shares deep links)
- **preconditions**: renamed app installed, seed data present
- **steps**: for each `pages[]` entry in `src/manifest.json`, navigate directly to its URL (not via
  in-app navigation) using the new `/apps/learniq/` prefix, including the 5 pages carrying a
  `deepLinks[].registerSlug` entry
  (`gotoAppRoute` — per the flow-editor lesson that a documented slot can render nowhere if only
  reached via a fresh page load is not exercised)
- **expected result**: every page renders its content (not a 404, not a blank shell); the 5
  `registerSlug`-bearing deep links resolve objects under the `learniq` register
- **test command**: `/test-functional`

### TC-8: Product framing copy is factual and audience-neutral
- **spec_ref**: `openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-product-framing-is-factual-and-audience-neutral-not-education-exclusive`
- **type**: functional
- **persona**: Mark (MKB Software Vendor — evaluates the app store listing before recommending it)
- **preconditions**: renamed app's `appinfo/info.xml` deployed
- **steps**: open the Nextcloud App Store admin listing page (or `occ app:getappvalue` /
  info.xml render) in both `en_US` and `nl_NL` locale
- **expected result**: summary/description in both locales describe school, training-provider, and
  company audiences; neither claims a capability absent from `docs/FEATURES.md`'s shipped tier
- **test command**: `/test-functional`

### TC-9: hermiq's course-recommendation feature degrades gracefully, not crashes, post-rename
- **spec_ref**: `openspec/changes/rename-to-learniq/contract.md`
- **type**: regression
- **persona**: n/a
- **preconditions**: hermiq installed alongside the renamed `learniq` app, hermiq's constants still
  reading `scholiq` (pre-hermiq-follow-up state)
- **steps**: trigger hermiq's course-recommendation feature for a learner with existing signal data
- **expected result**: hermiq returns its defined "unavailable" recommendation set (per
  `CourseRecommendationEngine`'s Gate 2), logs the "Scholiq is not installed" message, and does NOT
  throw an exception or 500 — confirms the contract.md graceful-degradation claim empirically rather
  than by inspection alone
- **test command**: `/test-regression` (run against a hermiq instance, cross-app scope)

### TC-10: l10n substitution preserves translation integrity
- **spec_ref**: `openspec/changes/rename-to-learniq/design.md#l10n-decision`
- **type**: regression
- **persona**: n/a
- **preconditions**: pre-rename `l10n/*.json` files as a baseline
- **steps**: diff every locale file before/after the substitution; assert only the literal
  `Scholiq`/`scholiq` token changed per line, nothing else in the surrounding sentence
- **expected result**: zero unrelated diffs across all 37 locale files; the small English-source-key
  subset (design.md's "(a)") shows a proper key rename + re-translated value, not a raw substitution
- **test command**: `/test-regression`

## Coverage Summary

| Requirement (app-metadata delta spec) | Covered by |
|---|---|
| Every identity boundary agrees after the rename | TC-1, TC-6, TC-7 |
| Action-authorization matrix preserved across the rename | TC-5 |
| A second migration run is a no-op | TC-4 |
| Pre-existing OpenRegister objects resolve after the register-slug migration | TC-3 |
| The register-slug migration is idempotent and non-destructive | TC-3, TC-4 |
| Product framing is factual and audience-neutral | TC-8 |
| (contract.md) hermiq graceful degradation | TC-9 |
| (design.md) l10n integrity | TC-10 |

All ADDED requirements in the delta spec have at least one covered scenario. No requirement is
deliberately left untested.

## Out of Scope

- Load/performance testing of the migration on a multi-million-object install — this app is new
  enough (v0.x, wedge-stage) that no install is expected to have that volume yet; `migration.md`'s
  Data Impact section already establishes the migration touches O(1) rows regardless of object count.
- Testing the hermiq-side constant rename itself — that is a separate change in a separate repo
  (`contract.md`'s Breaking Change Policy step 2); TC-9 only verifies *this* change's side of the
  contract (graceful degradation), not hermiq's eventual fix.
- Testing the App Store republish / repo-rename mechanics end-to-end (cannot be tested pre-merge —
  covered by design.md's sequencing decision to land it last, and by a manual verification task in
  `tasks.md` rather than an automated test).

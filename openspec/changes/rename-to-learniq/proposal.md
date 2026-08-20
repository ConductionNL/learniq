---
kind: code
depends_on: []
---

# Proposal: rename-to-learniq

## Summary

Rename the app from **Scholiq** to **Learniq** at every boundary where the old name is a contract: PHP namespace (`OCA\Scholiq` → `OCA\Learniq`), app id (`scholiq` → `learniq`, route prefix, install path, navigation route, MCP tool provider id), OpenRegister register slug (`scholiq` → `learniq`, with a data migration), `IAppConfig` keys stored under `scholiq.*` (including the ADR-023 action-authorization matrix), 14 filenames, Vue component names, and the product framing in `appinfo/info.xml`. This is a product reframe, not cosmetics: Scholiq is positioned as an education product ("open-source LVS + LMS"); the product is being repositioned as **Learniq**, a learning system a school, a training provider, or a company's L&D department can run equally well. The compliance-training wedge the app already ships (AVG/BIO/NIS2 refreshers, bulk enrolment, attestations, credential expiry, coverage reporting) is a corporate use case that has been wearing a school's name.

**Measured footprint**: **479 files, ~6,879 matching lines** across the whole tracked tree excluding
`openspec/` (`git grep -Il scholiq -- . ':!openspec'` / `git grep -Ic scholiq -- . ':!openspec'`
summed, as of commit `ec58854345c139b392ab898ed77f3030416526a8`). `openspec/` prose is excluded
deliberately — see "Footprint numbers" under Impact.

## Motivation

The rename is scheduled **now**, before the information-architecture and module-extraction work that is already queued, for two concrete reasons:

1. **Rebase cost compounds.** 17 other changes are currently in flight under `openspec/changes/` (`scholiq-mcp-adoption`, `hermiq-ai-tooling`, `portal-identity`, `portal-contribution`, `portal-parent`, `leaf-integrations`, `nav-restructure-dashboards`, `beta-surface-alignment`, and others). Every one of them touches the `OCA\Scholiq` namespace, the `scholiq` app id, or both. Renaming after they land means rebasing all of them through the rename; renaming now means they rebase onto `development` once, after this change merges, and everything after this change is born under the new name.
2. **The register-slug migration only gets harder.** OpenRegister stores each schema property as a real column in a per-schema shard table; the register itself is a row (`oc_openregister_registers`) keyed by `id`, with `slug` as a separate, renameable column. Renaming the slug today is a single-column `UPDATE` on however many register rows exist on each install. The longer the current name ships, the more installs (and the more downstream references baked into exports, integrations, and admin muscle memory) accumulate around `scholiq` as the slug.

## Affected Projects

- [ ] Project: `scholiq` — PHP namespace, app id, route prefix, register slug (+ migration), `IAppConfig` keys, MCP tool provider id, 14 renamed files, l10n handling, `info.xml` product framing (en+nl), repo rename / App Store id.

No other `apps-extra` project's source is edited by this change. Two projects — `hermiq` and `pipelinq` — have a **runtime** dependency on the literal string `scholiq` (app id and/or register slug and/or a webhook-dispatch target-system value): `hermiq/lib/Service/CourseRecommendationEngine.php` (`SCHOLIQ_APP_ID`, `SCHOLIQ_REGISTER` constants) and `pipelinq/lib/Listener/ObjectsMergedSyncListener.php` (`DOWNSTREAM_SYSTEMS` constant). Both dependencies are documented in `contract.md` as required follow-ups in their respective repos; neither is edited here (out of this change's `allowedEditRoots`).

## Scope

### In Scope

- PHP namespace `OCA\Scholiq` → `OCA\Learniq` across all 361 declarations under `lib/`, `tests/`; composer `psr-4` autoload map; psalm/phpstan/phpcs config paths and rule-set names.
- App id `scholiq` → `learniq`: `<id>` / `<namespace>` in `appinfo/info.xml`, install path (`custom_apps/scholiq` → `custom_apps/learniq`), route prefix (`/apps/scholiq/` → `/apps/learniq/`), the `scholiq.page.index` navigation route id, `package.json` name.
- `IAppConfig` keys currently stored under `scholiq.*` (`scholiq.course`, `scholiq.module`, `scholiq.listCourses`, `scholiq.getCourseDetails`, `scholiq.credentialVerify.verify`, the `scholiq.credential.signing.*` key family, and `scholiq.actions` — the ADR-023 action-authorization matrix) → `learniq.*`, with a migration that copies existing stored values rather than silently defaulting them empty under the new key.
- OpenRegister register slug `scholiq` → `learniq`, via an idempotent, non-destructive repair step modeled on `lib/Repair/RenameDutchColumns.php` (the `#331` vocabulary-rename reference pattern) — a slug-column `UPDATE`, not a shard-table rename (shard tables key on numeric register id, not slug, so they are unaffected).
- The 5 `deepLinks[].registerSlug` entries in `src/manifest.json` and the `x-openregister.app` / nested schema `slug` values in `lib/Settings/scholiq_register.json` (renamed to `lib/Settings/learniq_register.json`).
- The 117 literal `'scholiq'` register-slug/app-id string constants scattered across `lib/Controller/`, `lib/Lifecycle/`, `lib/Cron/`, `lib/Analytics/`, `lib/Engagement/`, `lib/Grading/`, `lib/CourseEvaluation/`, and sibling directories (measured via `git grep`; see design.md for the full boundary list).
- Filenames and Vue component names for all 14 files carrying the name in the filename (corrected count and composition — see design.md "Measured footprint").
- l10n: `Scholiq` appears inside translated string *values* in 37 `l10n/*.json` locale files (not 943 keys / ~30 locales as first estimated — see design.md). Decide and document how translated product-name occurrences are handled without invalidating every existing translation.
- Product framing in `appinfo/info.xml` `<summary>` and `<description>` (both `en` and `nl`): stop saying "LVS + LMS for education"; say what the app is — a learning system for schools, training providers, and companies. Factual only; no capability claims the app does not back.
- Repo rename (`ConductionNL/scholiq` → `ConductionNL/learniq`) and Nextcloud App Store id. `design.md` documents that an App Store id change is a **republish**, not an update, and that a redirect is left from the old repo.
- The MCP tool provider id (`lib/Mcp/ScholiqToolProvider.php` returns `'scholiq'` as its provider id) — coordinated with the in-flight `scholiq-mcp-adoption` change, which deletes this same file (see design.md "Sequencing with in-flight changes").

### Out of Scope

- Any domain-model vocabulary change (Praktijkopleider → WorkplaceTrainer, Cohort → Group, BPV → WorkPlacement, etc.) — owned by the separate `domain-model-neutral-vocabulary` change.
- Sector profiles (education / corporate) as a product feature.
- Menu / information-architecture restructuring.
- Moving any module to another app.
- Editing `hermiq`'s `CourseRecommendationEngine.php` constants or `pipelinq`'s `ObjectsMergedSyncListener.php` `DOWNSTREAM_SYSTEMS` constant (different repos, out of `allowedEditRoots`) — both flagged as required follow-ups in `contract.md`, not implemented here.
- Rewriting any `scholiq-*`-prefixed group id in the action-authorization matrix. The role/group vocabulary (`learners`, `instructors`, `team-leads`, `coordinators`, `hr`, `compliance-officers`, `guardians`, `administration-managers`) is fixed fleet-wide, unprefixed by design (OpenRegister's rbac-scopes requirement), and out of this rename's blast radius entirely — see design.md.

## Approach

Boundary-by-boundary mechanical rename (namespace → app id/routes → register slug + migration → config keys → filenames → l10n → product copy → repo/App-Store), each boundary landing with its own verification step, because — per the risk this proposal exists to manage — a green build is not evidence any single boundary actually still resolves at runtime. Full technical approach, the register-slug migration design, and the l10n decision are in `design.md`.

## New Dependencies

None.

## Impact

Every PHP class under `lib/` and `tests/` (namespace declaration line); `appinfo/info.xml`, `appinfo/routes.php` (indirectly — targets stay `controller#method`, only the app id prefix in generated URLs changes), `composer.json`, `psalm.xml`, `phpstan.neon`, `phpcs.xml`, `package.json`; `src/manifest.json`; `lib/Settings/scholiq_register.json` (renamed); 37 `l10n/*.json` files; every stored `IAppConfig` value under the `scholiq` namespace on every existing install; every OpenRegister object currently stored under the `scholiq` register slug on every existing install; `.github/workflows/*.yml` paths and the CI coverage baseline; the App Store listing and the GitHub repo itself.

**Footprint numbers.** `design.md`'s "Measured footprint" section is the source of truth; it names the
exact command and scope behind every figure so the next reader can re-run it rather than re-derive it.
Two scopes, both re-measured as of commit `ec58854345c139b392ab898ed77f3030416526a8`:

- **Whole tracked tree excluding `openspec/`** (the headline figure above, and the honest full rename
  surface): **479 files, 6,879 matching lines**. `openspec/` is excluded on purpose, not by oversight
  — renaming historical spec prose (already-archived changes that shipped under the `scholiq` name) is
  a separate editorial decision this change does not make; see design.md.
- **Product-code surface** (`lib src appinfo templates tests` — the scope the earlier 3,279 estimate
  actually covered): **405 files, 3,246 matching lines, 3,279 individual occurrences**. This is the
  subset most of `tasks.md`'s boundary tasks operate on directly.

An earlier draft of this footprint reconciliation (956 files / 5,731 lines) was itself wrong —
internally inconsistent (an occurrence count reported smaller than a matching-line count over the same
scope, which cannot happen) and not reproducible. The cause was measuring via a whole-repo `git grep`
while this same batch of spec-authoring agents was actively writing `openspec/` files, including this
change's own artifacts — the measurement and the thing being measured shared a working tree. See
design.md's "Measured footprint" section for the full account; the fix is scoping every measurement
with `:!openspec` and naming a commit, not re-deriving from a live working tree.

## Cross-Project Dependencies

Two `apps-extra` projects have a runtime dependency on the `scholiq` identity, found by sweeping every sibling app's `lib/`, `src/`, `appinfo/` trees via `git grep` and resolving each hit to "runtime literal" vs. "comment/prose/`@spec` reference" (full sweep results in `contract.md`):

- `hermiq`'s `CourseRecommendationEngine.php` hardcodes `SCHOLIQ_APP_ID = 'scholiq'` and `SCHOLIQ_REGISTER = 'scholiq'` and calls `IAppManager::isInstalled('scholiq')` plus `ObjectService::setRegister('scholiq')`. After this change ships, `isInstalled('scholiq')` returns `false` on any instance where the app has been reinstalled under `learniq`, and the feature **fails closed** (`CourseRecommendationEngine` already treats "Scholiq is not installed" as a first-class, non-fatal gate — it returns an "unavailable" recommendation set rather than throwing). No crash, but course recommendations silently go dark in hermiq until hermiq's constants are updated in a follow-up change.
- `pipelinq`'s `ObjectsMergedSyncListener.php` hardcodes `'scholiq'` inside a `DOWNSTREAM_SYSTEMS` runtime constant, fanning out a webhook per merge event to each named target system. Unlike hermiq's degradation, this one is **silent, not logged**: a mismatched `targetSystem` value is not a dispatch failure, so nothing in either app's logs indicates that merged-object sync for the renamed app has stopped arriving.

Both are documented in `contract.md` as required follow-ups in their respective repos (not implemented here — out of this change's `allowedEditRoots`), and both MUST be filed as tracked issues before this change is archived, per `contract.md`'s Breaking Change Policy.

No other `apps-extra` project has a runtime (non-comment, non-docstring) reference to the `scholiq` app id or register slug — `openregister`'s only hit is prose in a `$fleetComment` string (no code path); `openconnector`, `opencatalogi`, and `portaliq` mention `scholiq` only in comments, `@spec` references, or JSON `description` prose, not executable string literals.

## Risks

### Risk 1: Register-slug migration half-completes on an install with existing data
**Severity:** High — **Mitigation:** the repair step follows `RenameDutchColumns.php`'s proven shape — idempotent, non-destructive, logs counts rather than throwing, and is safe to re-run. It is also lower blast-radius than `RenameDutchColumns.php` itself: verified directly against a live database (not inferred from reading the reference pattern's code) that scholiq's register is a single row (`id=9, slug='scholiq'`) and its 118 shard tables are all named `oc_openregister_table_9_*` — keyed on the numeric id, not the slug, and confirmed with a decisive negative check (`SELECT COUNT(*) FROM information_schema.tables WHERE table_name ~ 'oc_openregister_table_[a-z]'` returns 0, i.e. no table name anywhere embeds a slug). So the migration is a single-row `UPDATE`, and no shard table is touched or can be orphaned by it. See `design.md`'s "Register-slug migration" section for the full measurement and `migration.md` for the abort/rollback position.

### Risk 2: A boundary is missed because a green build does not prove it still resolves
**Severity:** High — **Mitigation:** `tasks.md` gives each boundary (namespace, route prefix, `IAppConfig` keys, navigation route id, register slug, MCP tool id, CI paths) its own explicit verification step against a real running instance, not a lint pass. See design.md's boundary enumeration.

### Risk 3: hermiq's and pipelinq's cross-app couplings degrade post-rename
**Severity:** Medium — **Mitigation:** both documented in `contract.md` as required follow-ups in their respective repos. hermiq's failure mode is a graceful, already-engineered "unavailable" state (a functional regression with a known blast radius, not an outage). pipelinq's is worse in shape though not in severity — its webhook dispatch to a mismatched `targetSystem` fails **silently**, with no log line indicating anything went wrong — so its follow-up issue carries the higher urgency of the two even though the code change itself is a one-line array edit. Both follow-up issues MUST be filed (not necessarily merged) before this change is archived, per `contract.md`'s Breaking Change Policy.

### Risk 4: l10n retranslation cost
**Severity:** Low — **Mitigation:** design.md's l10n decision keeps the product-name substitution mechanical (string replace of the literal word, not a re-key), so existing translations are not invalidated.

## Rollback Strategy

Each boundary is independently revertible via `git revert` on its own commit(s) up until the register-slug repair step has actually run against a database with data. Before that point: revert code, no data was touched. After the repair step has run against at least one install: the `slug` UPDATE is symmetric (old→new is the same shape as new→old), so a rollback repair step re-running the same logic in reverse restores `scholiq` as the slug; nothing is deleted at any point (`RenameDutchColumns`'s "old column left in place" principle maps here to "no destructive operation on the register row"). App Store republish and repo rename are the two steps that are **not** cleanly revertible once executed (a new App Store listing cannot be un-published back onto the old id, and a repo rename requires GitHub's own redirect) — sequence these last, after every code/data boundary has been verified on a real install.

## Open Questions

None outstanding — see `DEFERRED_QUESTIONS` returned with this change for decisions made under uncertainty during artifact generation.

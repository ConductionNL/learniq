# Design: rename-to-learniq

## Measured footprint (reconciled, reproducible)

**This section was rewritten once already, and the rewrite was itself wrong** — the first correction
(935/3,279 → 956/5,731) claimed the two source numbers were "the same scope, two different metrics,"
which is arithmetically impossible: over one scope, an occurrence count can never be *smaller* than a
matching-line count, because every matching line contains at least one occurrence. 3,279 < 5,731 could
not both be true of the same scope, and neither 956 nor 5,731 could be reproduced by any invocation
tried afterward. The root cause: **the measurement and the thing being measured shared a working
tree.** `openspec/` alone carries 326 tracked files mentioning `scholiq`, and this same batch of spec
agents was actively writing ~20 more (this change's own artifacts) while the first re-measurement ran
— so a `git grep` over the repo root during spec authoring counts prose this change is itself adding,
and the count drifts upward for as long as authoring continues. That is the caution to carry forward:
**any footprint number taken while specs are being written must exclude `openspec/` explicitly
(`:!openspec` in `git grep`, or a directory allowlist) and must be stated as of a named commit**, or it
is not reproducible by the next reader.

All numbers below are re-measured as of commit `ec58854345c139b392ab898ed77f3030416526a8`
(2026-08-19 11:39:19 +0200), with the exact command next to each so they can be re-run rather than
re-derived.

**Scope A — product-code surface (`lib src appinfo templates tests`)**, the scope the original 3,279
estimate covered:

| Metric | Initial estimate | Measured | Command |
|---|---:|---:|---|
| Files | not separately estimated | **405** | `grep -rIl scholiq lib src appinfo templates tests \| wc -l` |
| Matching lines | not separately estimated | **3,246** | `grep -rI scholiq lib src appinfo templates tests \| wc -l` |
| Occurrences | 3,279 | **3,279** | `grep -rIoh scholiq lib src appinfo templates tests \| wc -l` |

3,279 ≥ 3,246 as arithmetic requires — the gap of 33 is lines carrying two or more occurrences (e.g.
`SCHOLIQ_REGISTER = 'scholiq'` PHPDoc lines, and JSON `description` strings that name the product
twice). These commands are **case-sensitive** (`scholiq`, not `Scholiq`); they undercount total
mentions but exactly reproduce the figures above, which is the property that matters here.

**Scope B — whole tracked tree excluding `openspec/`**, the honest full rename surface and the figure
`proposal.md` leads with:

| Metric | Measured | Command |
|---|---:|---|
| Files | **479** | `git grep -Il scholiq -- . ':!openspec' \| wc -l` |
| Matching lines | **6,879** | `git grep -Ic scholiq -- . ':!openspec' \| awk -F: '{s+=$NF} END{print s}'` |

`openspec/` is excluded from the headline number **deliberately, not by oversight**: renaming
historical spec prose (proposal/design/task text in already-archived changes) is a separate editorial
decision this change does not make — see "What `openspec/` exclusion means" below.

**Scope C — whole tracked tree including `openspec/`**, given for completeness, not as the number to
cite:

| Metric | Measured |
|---|---:|
| Files | **805** |
| Matching lines | **8,665** |

The Scope B → Scope C gap (326 files, 1,786 lines) is `openspec/`'s contribution — almost entirely
`openspec/changes/archive/*` records of already-shipped changes, which this change does not touch (see
"What `openspec/` exclusion means" below).

**Other measurements, unaffected by the contamination above** (these were checked directly against
specific, narrow patterns rather than a whole-tree sweep, so they hold as originally reported):

| Metric | Initial estimate | Measured | Note |
|---|---:|---:|---|
| PHP `namespace OCA\Scholiq` declarations | 361 | **361** | confirmed exact |
| Files carrying the name in the filename | 14 | **14** | confirmed exact — see corrected list below |
| Literal `'scholiq'` register-slug / app-id string constants under `lib/` | not estimated | **117** | `git grep -c "register: 'scholiq'\|'register' => 'scholiq'\|SCHOLIQ_REGISTER = 'scholiq'"` |
| `IAppConfig` keys under `scholiq.*` | not estimated | **9 distinct keys** (`scholiq.course`, `scholiq.module`, `scholiq.listCourses`, `scholiq.getCourseDetails`, `scholiq.credentialVerify.verify`, plus the 4-member `scholiq.credential.signing.*` family) | `git grep -o "'scholiq\.[a-zA-Z_.]*'"` |
| l10n locale files | ~30 | **37** | `ls l10n/*.json` |
| l10n occurrences of the literal string `Scholiq` | "943 keys" | **~36 occurrences per locale file** (≈1,332 total across 37 files) | not 943 distinct *keys* — most locale files repeat the word `Scholiq` inside translated value strings, not as a key name |

### What `openspec/` exclusion means

Scope B (the headline figure) does not touch `openspec/changes/archive/*` — those are records of
changes that already shipped under the `scholiq` name; rewriting their prose would falsify history for
no functional benefit. It also does not touch this change's own in-flight sibling directories
(`openspec/changes/scholiq-mcp-adoption/`, etc.) — those are covered by design.md's "Sequencing with
in-flight changes" section, not by a blanket text substitution. If a future editorial pass wants
`openspec/`'s historical prose to read consistently with the new name, that is a separate, explicitly
scoped change — not a silent expansion of this one's blast radius.

**Corrected 14-file list** (the proposal's "six `src/views/Scholiq*.vue`" undercounts by one — there are
seven):

```
lib/Mcp/ScholiqToolProvider.php
lib/Service/CoursePackage/ScholiqJsonCourseImporter.php
lib/Settings/scholiq_register.json
src/views/ScholiqAccessibilityStatement.vue
src/views/ScholiqAiProcessingDisclosure.vue
src/views/ScholiqCompliance.vue
src/views/ScholiqDashboards.vue
src/views/ScholiqLearnerHome.vue
src/views/ScholiqNotificationSettings.vue
src/views/ScholiqSettings.vue
tests/Unit/Mcp/ScholiqToolProviderTest.php
tests/Unit/ScholiqTest.php
tests/integration/scholiq.postman_collection.json
tests/wedge-scaffolds/scholiq-wedge.postman_collection.json
```

## Architecture Overview

This is not a new feature — it is a coordinated rename across eight independent boundaries, each of
which can silently fail without a build error (this is the risk the proposal exists to manage). The
boundaries, in the order they are landed and verified:

1. **PHP namespace** (`OCA\Scholiq` → `OCA\Learniq`) — 361 `namespace` declarations + every `use`
   statement referencing them, `composer.json` `psr-4` map, `psalm.xml` / `phpstan.neon` /
   `phpcs.xml` (config paths reference `lib/` by directory, not namespace, so these files need only
   their `<ruleset name="scholiq">` / header-comment text updated, not path changes).
2. **App id + routes** (`scholiq` → `learniq`) — `appinfo/info.xml` `<id>`, install path
   (`custom_apps/scholiq` → `custom_apps/learniq`), the generated `/apps/scholiq/` URL prefix
   (derived from `<id>`, not separately configured — no `appinfo/routes.php` change needed per
   ADR-016, since route *targets* stay `controller#method`), the `scholiq.page.index` navigation
   route id in `<navigations>`, `package.json` `name`.
3. **OpenRegister register slug** (`scholiq` → `learniq`, with migration) — see "Register-slug
   migration" below and `migration.md`.
4. **`IAppConfig` keys** (`scholiq.*` → `learniq.*`, with migration) — see "Config-key migration"
   below.
5. **Manifest + register JSON** — `src/manifest.json`'s 5 `deepLinks[].registerSlug` entries;
   `lib/Settings/scholiq_register.json` renamed to `lib/Settings/learniq_register.json`, its
   `x-openregister.app` field and nested schema `slug` field updated.
6. **Filenames + Vue component names** — the 14 files listed above, plus every `import` / route
   definition that references them by name.
7. **l10n** — see "l10n decision" below.
8. **Product framing + repo/App Store** — `appinfo/info.xml` `<summary>`/`<description>` (en+nl); CI
   workflow paths; repo rename + App Store republish (landed last, after every code/data boundary is
   verified — see proposal.md Rollback Strategy).

Each boundary gets its own task and its own verification step in `tasks.md` — none of them are
verified by "the build is green," because psalm/phpstan/phpcbf all pass on a namespace that resolves
to nothing at runtime just as readily as one that resolves correctly; the failure mode here is a
runtime 404, a silently-empty settings read, or an orphaned data row, none of which a static analyzer
sees.

## Register-slug migration

**Reference pattern**: `lib/Repair/RenameDutchColumns.php` (the `#331` vocabulary-rename step) is the
precedent for exactly this class of problem — OpenRegister does not offer a rename primitive, so any
identifier change that touches stored data needs an app-side `IRepairStep`. That step renames
*columns* inside shard tables; ours renames the register's own `slug` value, which is a simpler
operation with a smaller blast radius.

**This is a verified claim, not an inference from reading `RenameDutchColumns.php`'s code.** The
coordinator checked it directly against the live database on 2026-08-19, because it is the highest-
stakes assertion in this whole change — if it were wrong, the migration in `migration.md` would be
unsafe. Four measurements:

1. `oc_openregister_registers` has exactly one scholiq row: `id=9, slug='scholiq'`.
2. Scholiq's object storage is sharded into **118** tables, all named `oc_openregister_table_9_*` —
   one per schema, every one keyed on the numeric register id `9`, none on the slug.
3. Decisive negative check: `SELECT COUNT(*) FROM information_schema.tables WHERE table_name ~
   'oc_openregister_table_[a-z]'` returns **0**. No table name anywhere in the instance embeds a
   slug — the naming scheme is purely numeric, confirmed exhaustively, not just for this app's own
   tables.
4. `oc_openregister_objects` itself is empty (0 rows) on the instance checked — every object lives in
   the per-schema shards, so that table's row count is not usable as corroborating evidence either
   way and MUST NOT be cited as such.

- **Shard tables are keyed by the register's numeric `id`, not its `slug`** (table name pattern
  `oc_openregister_table_{register_id}_{schema_id}`, verified above, not merely read off
  `RenameDutchColumns::shardTables()`'s marker-matching logic). Renaming the slug therefore does
  **not** require touching any shard table, column, or row — it is a single-column `UPDATE` on the
  `oc_openregister_registers` table's `slug` column, for the row (`id=9`) whose slug currently reads
  `scholiq`.
- **New repair step**: `lib/Repair/RenameRegisterSlug.php`, registered in the same
  `<post-migration>` block as `RenameDutchColumns` in `appinfo/info.xml` (after it, since both are
  idempotent and order between them does not matter, but keeping the existing step's comment intact
  documents the ordering rationale that does apply to *it*).
- **Idempotent + non-destructive**, mirroring `RenameDutchColumns`'s safety properties:
  - `UPDATE oc_openregister_registers SET slug = 'learniq' WHERE slug = 'scholiq'` — only fires when
    the old slug is present; a second run finds no matching row and is a no-op.
  - No `DELETE`, no destructive operation of any kind. If a future rollback is needed, a reverse step
    (`UPDATE ... SET slug = 'scholiq' WHERE slug = 'learniq'`) is symmetric and safe to write.
  - **Collision guard**: if a row with `slug = 'learniq'` already exists (e.g. a second register
    manually created with that slug, or a re-run after a partial manual fix), the step MUST refuse
    that row and log a warning rather than merge or overwrite — same posture as
    `RenameDutchColumns::hasCollision()`.
- **What does NOT need migration**: any register whose slug is unrelated to `scholiq` (none exist in
  this app — `scholiq` is the only register the app owns); any OpenRegister internal index or cache
  keyed by `id` rather than `slug`.
- **What DOES need a code-side follow-up in the same PR**: every one of the 117 literal `'scholiq'`
  string constants in `lib/` that pass a register slug to `ObjectService` (e.g.
  `RolloverController::saveObject(register: 'scholiq', ...)`, the 9 `SCHOLIQ_REGISTER = 'scholiq'`
  class constants) MUST change to `'learniq'` in the *same* deploy as the repair step runs — a lookup
  against the old slug after the migration has run returns nothing (by design; see the app-metadata
  spec's "pre-existing objects resolve" scenario), so code and data must move together, not
  data-first-then-code.

### Abort / rollback position (proposal.md's Rollback Strategy, detailed)

- **Before the repair step runs on any install**: pure code revert, nothing to undo.
- **After the repair step has run on an install with data**: the register row's `slug` is `learniq`
  and every object under it is unchanged (shard tables never touched). A rollback repair step running
  `UPDATE ... SET slug = 'scholiq' WHERE slug = 'learniq'` restores the pre-migration state exactly,
  because the operation is symmetric and no data was ever moved, only one column's value. There is no
  half-completed state possible for *this* particular migration (unlike `RenameDutchColumns`, which
  can leave some columns renamed and others back-filled): a single `UPDATE` on a single row either
  commits or it doesn't — Nextcloud's migration framework runs each repair step's `run()` inside the
  request/CLI process, and `IDBConnection::executeStatement()` on `UPDATE` is one atomic statement.
- **What is genuinely irreversible**: the App Store republish and the GitHub repo rename (see
  "App Store + repo rename" below) — these are the last two tasks in `tasks.md`, gated on every data
  and code boundary being verified first.

## Config-key migration

Nine `IAppConfig` keys move from the `scholiq` app-config namespace to `learniq`. Nextcloud's
`IAppConfig` is namespaced by app id at the storage layer (`oc_appconfig.appid`), so this is not a
key-rename within one namespace — it is a **copy across app-config namespaces**, because the app's own
id is changing.

- **New repair step**: `lib/Repair/MigrateAppConfigKeys.php`, in the same `<post-migration>` block.
- For each of the 9 known keys: read `IAppConfig::getValueString('scholiq', <key>, '')`; if non-empty
  and the destination is not already set, write it to `IAppConfig::setValueString('learniq', <key>,
  <value>)`. Idempotent by construction (checks destination before writing) and non-destructive (the
  old `scholiq.*` values are left in place, not deleted — mirrors `RenameDutchColumns`'s "old column
  left in place" principle).
- `scholiq.actions` (the ADR-023 action-authorization matrix) is the highest-value key here: an admin
  who has customized which groups can invoke which actions MUST NOT have that silently reset to the
  all-admin default because the matrix is read from a key that no longer has their value under it.
  This is the concrete case the app-metadata delta spec's scenario "Action-authorization matrix is
  preserved across the rename" tests.

**Group ids are explicitly out of this migration's scope.** The role/group vocabulary used by the
action-authorization matrix (`learners`, `instructors`, `team-leads`, `coordinators`, `hr`,
`compliance-officers`, `guardians`, `administration-managers`) is fixed fleet-wide and is deliberately
**unprefixed** — per OpenRegister's rbac-scopes requirement, group ids are free-form strings shared
between apps by design, not app-namespaced identifiers like `scholiq.actions` is. `rbac-declare-groups`
(a separate, in-flight change) retires any app-prefixed group-id convention entirely, and
`fix-dead-role-gates` repoints the resolver that reads them. Because these group ids never carried a
`scholiq`/`learniq` prefix to begin with, this change's config-key migration copies the *values*
stored under `scholiq.actions` (which reference these unprefixed group ids) verbatim — it does not,
and must not, rewrite any group id inside that matrix.

## Sequencing with in-flight changes

`openspec/changes/scholiq-mcp-adoption/` (in flight, not archived) **deletes**
`lib/Mcp/ScholiqToolProvider.php` and replaces it with OpenRegister's derived `x-openregister-mcp`
surface. This change **renames** that same file (to `lib/Mcp/LearniqToolProvider.php`) as part of
boundary 6. Two changes touching the same file's identity is a real collision, not a hypothetical one.

**Decision** (recorded here, not deferred to the user — see DEFERRED_QUESTIONS in the change summary
for why): this rename change proceeds on `ScholiqToolProvider.php` as it exists today (renaming it to
`LearniqToolProvider.php`, updating its provider id from `'scholiq'` to `'learniq'`), because:

1. The proposal's own motivation section states the rename is scheduled *before* other in-flight
   changes precisely so they rebase onto it once, not the other way around.
2. If `scholiq-mcp-adoption` lands first, its own PR deletes the file — this rename's corresponding
   task becomes a no-op (nothing to rename) and the rename instead only needs to update the derived
   MCP surface's app-id-derived tool-name prefix (`scholiq.{schema}.{verb}` → `learniq.{schema}.{verb}`,
   which falls out of boundary 2 automatically since it is derived from the app id, not hand-written).
3. Either ordering is safe — this is a same-repo sequencing question resolved by whichever change's PR
   merges first, not a cross-repo coordination problem like the hermiq case in `contract.md`.

`tasks.md`'s MCP-boundary task is written to handle both orderings: "rename if present, otherwise
confirm the app-id-derived tool prefix already reads `learniq`."

## l10n decision

37 `l10n/*.json` locale files contain the literal string `Scholiq` inside translated value strings
(not as JSON keys — the keys are the English source strings; `Scholiq` appears where the product name
was translated inline, e.g. `"Welcome to Scholiq": "Welkom bij Scholiq"`).

**Decision**: mechanical, case-preserving string substitution of the literal word `Scholiq` → `Learniq`
(and `scholiq` → `learniq` where lowercase) inside every locale file's **values**, leaving every key
and every other word in every translation untouched. This is deliberately *not* a re-translation and
*not* a re-key:

- Re-keying (changing the English source-string keys because they mention the product name) would
  invalidate every one of the ~1,332 existing translations across 37 locales, forcing a full
  re-translation pass this change does not need and should not gate on.
  the source strings that carry the product name (e.g. `"Welcome to Scholiq"`) DO need their English
  key text updated too, since the key IS the English string in this app's l10n convention — those are
  a small, enumerable subset (the ones matching `/Scholiq/` in the **English** `l10n/en.json` keys)
  and get a proper key rename with the corresponding value re-translated per locale, not a blind
  substitution.
- For every *other* locale's translated value (the ~36 occurrences per non-English file), a literal
  find-and-replace of the word is safe: `Scholiq`/`scholiq` is a proper noun that does not decline or
  conjugate in any of the 37 languages shipped (verified by spot-checking `nl.json`, `de.json`,
  `fr.json`, `es.json` — the word appears as an unmodified inserted token, not inflected).
- **Task split**: (a) find every `l10n/en.json` key containing `Scholiq` and rename the key +
  translate `Learniq` into the corresponding value in all 37 locale files (small, enumerable set —
  the English-source subset only); (b) mechanically substitute the literal word inside every other
  already-existing value across all 37 files. Both land in the same task since (b) is a pure text
  substitution with no judgment calls, and (a) is small enough to review in the same PR.

## Nextcloud Integration

- **Repair steps**: `RenameRegisterSlug` and `MigrateAppConfigKeys` (new, `IRepairStep`), registered
  in `<post-migration>` alongside the existing `InitializeSettings`, `InitializeActions`,
  `RenameDutchColumns`.
- **Services/Controllers**: no new services. Every existing controller/service/listener/lifecycle
  guard that carries a `SCHOLIQ_REGISTER` constant or an inline `'scholiq'` register-slug argument is
  edited in place (117 call sites — mechanical, not a redesign).
- **Constructor-injected OpenRegister dependency (ADR-083)**: unaffected in shape — scholiq is one of
  the two apps (with hermiq) that already inject `ObjectService` at scale (114 constructor-injected
  sites per ADR-083's fleet measurement). This rename does not touch *how* the dependency is obtained,
  only the string arguments passed to methods on it. ADR-084's `ObjectServiceInterface` type-hint
  (once available) is out of scope for this change.
- **MCP**: `lib/Mcp/ScholiqToolProvider.php` → `lib/Mcp/LearniqToolProvider.php`, provider id
  `'scholiq'` → `'learniq'` — see "Sequencing with in-flight changes" above for the conditional
  handling.

## Security Considerations

No security-relevant behavior changes — no auth attribute, no RBAC rule, and no action-authorization
mapping changes shape. The one security-adjacent risk is the config-key migration: if
`MigrateAppConfigKeys` were to *default* `learniq.actions` to the all-admin-only seed instead of
*copying* the admin's existing `scholiq.actions` customization, that would be safe-by-default (nothing
is opened up) but would silently **lock out** any admin who had broadened access — an availability
regression, not a confidentiality one, but still worth stating: the migration is a copy, never a
re-seed, specifically to avoid this failure mode.

## File Structure

```
lib/
  AppInfo/Application.php              # APP_ID constant: 'scholiq' → 'learniq'
  Repair/
    RenameRegisterSlug.php             # NEW — register slug migration
    MigrateAppConfigKeys.php           # NEW — IAppConfig key migration
    RenameDutchColumns.php             # unchanged (unrelated migration, already merged)
  Mcp/LearniqToolProvider.php          # renamed from ScholiqToolProvider.php (see sequencing note)
  Service/CoursePackage/LearniqJsonCourseImporter.php   # renamed from ScholiqJsonCourseImporter.php
  Settings/learniq_register.json       # renamed from scholiq_register.json
  Controller/**, Lifecycle/**, Cron/**, Analytics/**, Engagement/**, Grading/**, CourseEvaluation/**
                                        # 117 literal 'scholiq' string-constant call sites edited in place
src/
  manifest.json                        # 5 deepLinks[].registerSlug entries
  views/Learniq*.vue                   # 7 files renamed from Scholiq*.vue
appinfo/
  info.xml                             # <id>, <namespace>, <summary>, <description> (en+nl), nav route id
composer.json, psalm.xml, phpstan.neon, phpcs.xml, package.json
l10n/*.json                            # 37 locale files
.github/workflows/*.yml                # path references + coverage baseline
tests/
  Unit/Mcp/LearniqToolProviderTest.php # renamed from ScholiqToolProviderTest.php
  Unit/LearniqTest.php                 # renamed from ScholiqTest.php
  integration/learniq.postman_collection.json          # renamed
  wedge-scaffolds/learniq-wedge.postman_collection.json # renamed
```

## Trade-offs

- **Imperative `IRepairStep` for both migrations, not a declarative schema behavior.** ADR-031's
  declarative-vs-imperative decision applies to *runtime business logic* (lifecycle, aggregations,
  derived fields, notifications, relations, widgets) declared in the schema register. A one-time
  identity rename executed at install/upgrade time is infrastructure, not business logic, and has no
  `x-openregister-*` declarative equivalent — `RenameDutchColumns` establishes this is already the
  accepted pattern for this exact problem class in this app, so this change follows it rather than
  inventing a new mechanism.
- **Copy-not-move for `IAppConfig` keys.** Considered deleting the old `scholiq.*` keys after copying.
  Rejected: no operational benefit (stale keys under an app id nothing reads by is harmless, per
  `RenameDutchColumns`'s "old column left in place" precedent), and deletion adds a failure mode
  (deleting before confirming the copy succeeded) for zero benefit.
- **Mechanical l10n substitution over re-translation.** Considered re-keying every source string
  mentioning the product name and running a full re-translation pass. Rejected as disproportionate:
  the product name is a proper noun that does not need translation, only substitution; forcing a
  re-translation pass would block this change on 37 locales' worth of translator availability for a
  change that is not actually about the *wording*, only the *name*.
- **App Store republish and repo rename land last, not first.** Considered doing the highly-visible
  parts (repo rename, App Store listing) early to "commit" to the new name. Rejected: both are the
  least reversible steps in the whole change, and every other boundary is fully revertible until they
  execute — sequencing them last means every code/data risk is retired before the irreversible step,
  not after.

# Migration: rename-to-learniq

> Scholiq is a thin client — it owns no database tables of its own (all persistence is via
> OpenRegister's `ObjectService`), so this is not a Nextcloud versioned `Migration` class
> (`changeSchema()` / `lib/Migration/VersionXXXXXXXXXX.php`) — there is no schema for this app to
> change. Both migrations below are `IRepairStep` classes registered in `appinfo/info.xml`'s
> `<repair-steps><post-migration>` block, the same mechanism `lib/Repair/RenameDutchColumns.php`
> already uses for the equivalent "rename something OpenRegister does not have a rename primitive
> for" problem. The template's "Migration Class" section is repurposed accordingly.

## Current State

**Verified against the live database on 2026-08-19** (not inferred from reading
`RenameDutchColumns.php`'s code — this migration's safety claim was checked directly, because it is
the highest-stakes assertion in this change):

- OpenRegister's `oc_openregister_registers` table has exactly one scholiq row: `id=9,
  slug='scholiq'` (this app declares exactly one register, per `x-openregister.app: "scholiq"` in
  `lib/Settings/scholiq_register.json`).
- Scholiq's object storage is sharded into **118** tables, all named `oc_openregister_table_9_*` —
  one per schema, every one keyed on the numeric register id `9`, none on the slug.
- Decisive negative check: `SELECT COUNT(*) FROM information_schema.tables WHERE table_name ~
  'oc_openregister_table_[a-z]'` returns **0** across the whole instance — no shard table anywhere
  embeds a slug in its name, confirming the naming scheme is purely numeric, not just for this app.
- `oc_openregister_objects` is empty (0 rows) on the instance checked — every object lives in the
  per-schema shards. That table's row count is **not** usable as corroborating evidence for or
  against this migration's safety and must not be cited as such.
- `oc_appconfig` has rows with `appid = 'scholiq'` for up to 9 known keys (`course`, `module`,
  `listCourses`, `getCourseDetails`, `credentialVerify.verify`, and the 4-member
  `credential.signing.*` family), plus any admin-set values under `scholiq.actions`.

**Conclusion these measurements support**: the register-slug rename is a single-row `UPDATE`; no
shard table is touched; object data cannot be orphaned by this migration.

## Target State

- The same register row now has `slug = 'learniq'`; same `id`, same shard tables, same objects, same
  ownership/permissions/relations.
- `oc_appconfig` has the same values duplicated under `appid = 'learniq'` for every key that had a
  non-empty value under `scholiq`. The `scholiq`-namespaced rows are left in place (not deleted).

## Migration Class (repair steps)

```
File: lib/Repair/RenameRegisterSlug.php
Registered: appinfo/info.xml <repair-steps><post-migration>, alongside RenameDutchColumns
Key operation:
  UPDATE oc_openregister_registers SET slug = 'learniq' WHERE slug = 'scholiq'
  (only when no existing row already has slug = 'learniq' — collision guard, log-and-skip on conflict)
```

```
File: lib/Repair/MigrateAppConfigKeys.php
Registered: appinfo/info.xml <repair-steps><post-migration>
Key operation, per known key K in {course, module, listCourses, getCourseDetails,
  credentialVerify.verify, credential.signing.archived_keys, credential.signing.fingerprint,
  credential.signing.private, credential.signing.public, actions}:
  old = IAppConfig::getValueString('scholiq', K, '')
  IF old != '' AND IAppConfig::getValueString('learniq', K, '') == '':
      IAppConfig::setValueString('learniq', K, old)
```

## Migration Steps

1. `RenameRegisterSlug::run()` resolves the register row(s) whose slug is exactly `scholiq` (not a
   prefix match — this app owns exactly one register, unlike `RenameDutchColumns`'s prefix-scan across
   several `bpv*`-style clusters).
2. Guard: if a row with `slug = 'learniq'` already exists, log a warning and skip (do not merge, do
   not overwrite) — matches `RenameDutchColumns::hasCollision()`'s posture.
3. Execute the single-statement `UPDATE ... SET slug = 'learniq' WHERE slug = 'scholiq'`.
4. `MigrateAppConfigKeys::run()` iterates the 9 known keys, copying each non-empty `scholiq.*` value to
   `learniq.*` only when the destination is still empty.
5. Both steps log a one-line summary (`N register(s) renamed`, `M config key(s) migrated, P already
   present`) via `IOutput::info()`, matching `RenameDutchColumns`'s reporting shape.
6. **Ordering relative to code**: the app version that ships these repair steps MUST be the same
   version that also changes every one of the 117 literal `'scholiq'` register-slug string constants
   in `lib/` to `'learniq'` (see design.md "Register-slug migration"). A version that ships the repair
   step without the code change would migrate the data out from under code that is still looking for
   it under the old slug — this MUST NOT happen as two separate releases.

## Data Impact

- **Register-slug rename**: affects exactly 1 row in `oc_openregister_registers` per install (this app
  declares exactly one register). Zero rows in any shard table are touched, moved, or transformed — the
  object count under the register is unaffected because shard tables key on `register_id`, not `slug`.
- **Config-key migration**: affects up to 9 rows in `oc_appconfig` per install — fewer if the admin
  never customized some of them (an unset key has nothing to copy, which is correct: nothing to
  migrate is not a failure).
- **Live-data safety**: both steps run inside Nextcloud's normal `occ upgrade` / repair-step execution
  window, which already runs with the app's PHP-FPM workers stopped from serving that app's own
  requests (standard Nextcloud upgrade posture) — no concurrent write can race the `UPDATE` or the
  `IAppConfig` copy.

## Rollback Procedure

- **Register slug**: `UPDATE oc_openregister_registers SET slug = 'scholiq' WHERE slug = 'learniq'` —
  symmetric, safe, restores exactly the pre-migration state because nothing else was touched.
- **Config keys**: no rollback action needed — the old `scholiq.*` rows were never deleted, so reverting
  the *code* (which reads `learniq.*`) back to a version that reads `scholiq.*` finds its data exactly
  where it left it. If a value was written under `scholiq.*` *after* the migration ran (e.g. an admin on
  a not-yet-upgraded worker saved a setting during a rolling deploy), that later write is NOT
  automatically re-copied forward — call this out explicitly to the on-call admin doing a rollback, and
  treat it as a known limitation of copy-based (not synchronized) config migration rather than a defect
  to fix in this change.
- **Neither repair step is destructive**, so "rollback" here means "run the reverse `UPDATE` / revert
  the code," never "restore from backup."

## Validation

- `SELECT slug FROM oc_openregister_registers WHERE slug IN ('scholiq', 'learniq')` — expect exactly
  one row, `slug = 'learniq'`, after migration; zero rows for `scholiq` (the row was renamed in place,
  not duplicated).
- `SELECT COUNT(*) FROM oc_openregister_table_9_{schema_id}` for every one of the app's **118**
  schema shard tables (verified count as of 2026-08-19 — re-check on the target install, since a
  schema added between measurement and merge would change this number), compared before/after the
  migration — MUST be identical (the migration must not change object counts).
- `SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'oc_openregister_table_9\_%'`
  before and after the migration — MUST return the same 118 table names (proves no shard table was
  renamed, dropped, or recreated as a side effect).
- Fetch a specific pre-existing object by id via `ObjectService::find(id: <id>, register: 'learniq',
  schema: <schema>)` and diff its full property set against a pre-migration export of the same object —
  MUST be byte-identical apart from any unrelated concurrent edits.
- `occ config:app:get learniq actions` returns the admin's customized action-authorization matrix (not
  the all-admin default) on an install where the admin had customized `scholiq.actions` before the
  upgrade.
- Re-run both repair steps a second time (`occ maintenance:repair --include-expensive` or equivalent)
  and confirm the log output reports zero additional changes — proves idempotency.

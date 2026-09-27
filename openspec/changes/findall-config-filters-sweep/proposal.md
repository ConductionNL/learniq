---
kind: code
depends_on: []
---

# Proposal: findall-config-filters-sweep

## Summary

173 `ObjectService::findAll()` reads under `lib/` named their register and schema in the wrong place. OpenRegister only reads them from `filters`. This change moves both keys into `filters` on every call, fixes four configs that ended up with two `filters` keys, and adds a test that fails when the old shape comes back.

## Motivation

OpenRegister's `ObjectService::prepareFindAllConfig()` (openregister `lib/Service/ObjectService.php`, `development` at `d611a366`, lines 1519-1533) sets the read context from `$config['filters']['register']` and `$config['filters']['schema']` only. `findAll()` (line 1464) then hands `$this->currentRegister` and `$this->currentSchema` to the get handler. A `register` or `schema` key at the top level of the config is never read.

Learniq put both keys at the top level on 170 literal configs and three configs built in a variable (`ComplianceRollupService::rows()`, `RegulationAssignmentService::rows()`, `LearniqToolProvider::buildCourseListConfig()`). Those reads ran with no schema, or with whatever schema an earlier call on the request-shared `ObjectService` left behind. Filters such as `learnerId` or `cohortId` were then applied to the wrong table or to no table. Guards, listeners, report cards, timetables and rollups all read through this path.

The r2-assessment lane found the pattern on 2026-09-27 (TRACKER-R2, 16:05). The unit tests never saw it: every double of `findAll()` read `$config['schema']`, the same wrong key, so the doubles and the code agreed with each other and not with OpenRegister.

## Affected Projects

- [x] Project: `learniq` (repo `ConductionNL/learniq`), 102 files under `lib/` and the doubles in 73 test files.

## Scope

### In Scope

- Move `register` and `schema` into `filters` on every `ObjectService::findAll()` call under `lib/`, keeping the value expressions unchanged.
- Where `filters` is a variable, merge the two keys in with `array_merge($filters, [...])`. Where it is a `tenantScoped()` call, add them to its `filters:` literal.
- A regression test (`tests/Unit/FindAllConfigScopeTest.php`) that tokenises `lib/` and fails on a top-level `register`/`schema` key in any findAll config, including one assembled in a variable, and on a config with a duplicate key. Control tests prove the detector flags each defect.
- Update the `findAll()` doubles in the unit tests to read `$config['filters']['schema']` and to skip the two scope keys when they match rows on `filters`.

### Out of Scope

- `ObjectService::find()` and `saveObject()` calls: they take `register:` and `schema:` as named arguments and were already correct.
- Behaviour changes inside the callers beyond the scope keys. A read that now finds rows it missed before is the fix, not a new feature.
- Seed data, the register, the manifest and any frontend code.

## Approach

A scripted, bracket-aware rewrite of each call site, read back with `php -l` and a duplicate-key scan, followed by hand edits for the six sites the script refused (two `tenantScoped()` expressions, one single-line config, three variable-built configs) and the four sites where a comment hid the `filters` key from the script. The detail and the counts are in design.md.

## New Dependencies

None.

## Impact

Every learniq read through `ObjectService::findAll()`: lifecycle guards and handlers, event listeners, background jobs, controllers, the MCP tool provider and the aggregation services. No API, route, schema or UI changes.

## Cross-Project Dependencies

None. The change relies on OpenRegister behaviour already on `development`.

## Risks

### Risk 1: a read now scoped to the real schema returns different rows

**Severity:** Medium. **Mitigation:** that is the intended fix. A read that used to hit the wrong table now hits the right one. A schema constant that does not exist in the register now fails closed in OpenRegister's `RegisterScopedSchemaResolver` instead of silently reading another app's table. The full unit suite is green apart from 14 register tests that fail on `development` too.

### Risk 2: a mechanical rewrite that parses but is wrong

**Severity:** Medium. **Mitigation:** the first pass produced four configs with two `filters` keys, which `php -l` accepts. A duplicate-key scan caught them, they were fixed by hand, and the regression test now flags a duplicate key too.

## Rollback Strategy

Revert the merge commit. The change touches no stored data.

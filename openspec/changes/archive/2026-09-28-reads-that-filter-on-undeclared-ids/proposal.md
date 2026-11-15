---
kind: code
depends_on: [findall-filter-keys-declared]
---

# Proposal: reads-that-filter-on-undeclared-ids

## Summary

37 object reads under `lib/` filtered on `id` or `uuid`. No learniq schema declares either property, and OpenRegister answers a filter on an undeclared property with `1 = 0`, so every one of these reads returned nothing. Each now passes the object id in the config's `ids`, keeping its tenant and state filters, and `FindAllConfigScopeTest` refuses an `id` or `uuid` filter key for every read, including reads whose schema is chosen at run time.

## Motivation

#1047 (findall-config-filters-sweep) found 22 such reads and left them for a follow-up; #1119 (findall-filter-keys-declared) listed 29 in the `KNOWN_UNDECLARED` ratchet of `FindAllFilterKeysAreDeclaredTest` and opened #1116. That test resolves the schema a call names, so it skipped reads whose schema is a parameter. A scan that does not need the schema found eight more, among them `ObjectRowReader::load()` (every competency lookup through it), `WerkprocesGradeEmitHandler` and `PortfolioGradeEmitHandler` (grade emission from BPV assessments and portfolios), and the `fetchOne()` helpers of `AssessmentDrawResolver`, `ItemAnalysisService` and `ItemAnalysisRecomputeHandler`.

What each read did on a live instance, per OpenRegister `MagicSearchHandler::applyObjectFilters()` (openregister `development` at `9c378221`): an `id` or `uuid` key is not a column of the schema's table, so the filter compiles to `1 = 0` and the read is empty. Guards among them refused every transition they check (a Programme could never publish, a Submission never hand in, an AttendanceFlag never report); listeners skipped their work (session change notices, cohort group provisioning, data exchange outcomes, school advice to ROD).

The unit tests missed it because their doubles matched rows on `filters.id` or `filters.uuid`: the double spoke the same wrong dialect as the code.

## Affected Projects

- [ ] Project: `learniq`: 30 files under `lib/`, `tests/Unit/FindAllConfigScopeTest.php`, `tests/Unit/Register/FindAllFilterKeysAreDeclaredTest.php`, nine test doubles.

## Scope

### In Scope

- Every `findAll()` under `lib/` with an `id` or `uuid` filter key: the id moves to `ids`, other filters (tenant, lifecycle) stay in `filters`.
- `FindAllConfigScopeTest` gains a schema-independent scan for `id` and `uuid` filter keys, with controls for a literal, `tenantScoped()`, `array_merge()` over a variable, and a variable assigned by key.
- The 29 fixed entries leave `KNOWN_UNDECLARED`.
- Test doubles that matched on `filters.id`/`filters.uuid` read `ids`, the key OpenRegister honours.

### Out of Scope

- The six remaining `KNOWN_UNDECLARED` entries (xAPI dotted keys, `lesson.xapiObjectId`, `session.sessionDayBucket`). Each needs a schema or query decision; they stay tracked in #1116.

## Approach

Use the key OpenRegister reads: `ObjectService::findAll()` threads a config's `ids` to `MagicMapper::findAll()`, which passes it as `_ids` to the search handler, where it is applied next to the ordinary filters (openregister `lib/Db/MagicMapper.php` around line 9137, `MagicSearchHandler::applyContentFilters()` around line 596). A read by id keeps its tenant and state filters in the same query, so the H1 tenant scoping these reads carry is unchanged.

The edit is mechanical and was scripted from the scan's own list, then read back: `php -l` on every file, the diff (one `ids` per read, 37 removed keys), and the tests.

## New Dependencies

None.

## Impact

Reads that returned nothing now return the object. Guards that refused everything now decide on the object; listeners that skipped now run.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A path that never ran now runs

**Severity:** Medium. **Mitigation:** Behaviour that was dark on live instances (grade emission from werkproces and portfolio assessments, cohort group provisioning, session change notices) starts working on the first event after deploy. Each already has its own unit tests, which now exercise the real read shape.

## Rollback Strategy

Revert the merge commit; the reads go back to returning nothing.

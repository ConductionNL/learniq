# Design: reads-that-filter-on-undeclared-ids

## Architecture Overview

No new class. Each affected read keeps its shape and moves one key: `filters.id` or `filters.uuid` becomes the config's `ids`.

```
before: findAll(['filters' => ['register' => 'learniq', 'schema' => 'cohort', 'id' => $cohortId], 'limit' => 1])
after:  findAll(['ids' => [$cohortId], 'filters' => ['register' => 'learniq', 'schema' => 'cohort'], 'limit' => 1])
```

## Decisions

### D1. `ids`, not `find()`

Both reach the object. `ids` keeps each read's other filters in the same query (every tenant-scoped read here filters `tenant_id`, one also `lifecycle`), so the H1 tenant rule stays in OpenRegister's query instead of being re-implemented in PHP after a `find()`. It is also the one-key change #1116 asked for, and it keeps the callers' result handling (a list, `limit: 1`) as it was. OpenRegister has threaded `ids` through `MagicMapper::findAll()` since openregister `095b57eb` (2026-06-10).

### D2. A schema-independent scan

`FindAllFilterKeysAreDeclaredTest` checks keys against the schema a call names and skips calls whose schema is a variable. That is how `ObjectRowReader::load()` and the `fetchOne()`/`loadRow()` helpers stayed out of its list. `id` and `uuid` are undeclared on every learniq schema, so a scan for those two keys needs no schema at all. It lives in `FindAllConfigScopeTest`, next to the scope-key scan, and uses the same tokenizer.

### D3. Doubles speak OpenRegister's dialect

Nine test doubles matched rows on `filters.id` or `filters.uuid`. They now match on `ids`, the key OpenRegister honours, so the old code fails them (17 failures on `development`'s `lib/`).

## Declarative-vs-imperative decision (ADR-031)

No behaviour is added or moved; every read stays where it was.

## Security Considerations

Tenant filters are unchanged and stay in the query. Reads that failed closed (guards refusing because they found nothing) now decide on the real object; each guard's own rules apply as designed.

## File Structure

30 files under `lib/` (listed in the PR), `tests/Unit/FindAllConfigScopeTest.php`, `tests/Unit/Register/FindAllFilterKeysAreDeclaredTest.php`, and the doubles in `TimetableControllerTest`, `AssessmentScoringHandlerTest`, `AssessmentAutoScoreActionTest`, `ReportCardVisibilityGuardTest`, `SessionChangeNoticeHandlerTest`, `RejectionMappingHandlerTest`, `DataExchangeRunHandlerTest` (two), `TimetableConflictDetectorTest`.

## Seed Data

No schema change, no seed change.

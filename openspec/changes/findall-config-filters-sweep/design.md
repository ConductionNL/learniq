# Design: findall-config-filters-sweep

## Context

OpenRegister `lib/Service/ObjectService.php` at `development` (`d611a366`):

- `findAll()` (line 1464) calls `prepareFindAllConfig()` (line 1468) and passes `register: $this->currentRegister`, `schema: $this->currentSchema` to `GetObject::findAll()` (lines 1473-1486).
- `prepareFindAllConfig()` (lines 1513-1537) calls `setRegister()` only when `$config['filters']['register']` is set (1519-1524) and `setSchema()` only when `$config['filters']['schema']` is set (1527-1533). Nothing reads a top-level `register` or `schema`.
- `GetObject::findAll()` (openregister `lib/Service/Object/GetObject.php`, line 294) receives the filters unchanged, so the scope keys travel with the other filters, which is how every correct caller in the fleet already writes it.

## Measurement before writing

Surveyed with a bracket-aware scanner over `lib/` on `origin/development` (`721b28ac`):

| shape | calls |
|---|---|
| top-level register + schema, `filters` a literal (multi-line 38, single-line 58) | 96 |
| top-level register + schema, `filters` a variable or expression | 65 |
| top-level register + schema, no `filters` | 9 |
| config assembled in a variable | 3 |
| already correct | 0 |

173 calls, all on `$this->objectService`. The regression test run against the untouched tree reports 346 findings (173 x 2 keys), so it sees every one.

## Rewrite rules

| shape | result |
|---|---|
| `filters` multi-line literal | the two keys become its first two entries |
| `filters` single-line literal | expanded to multi-line with the two keys first |
| `filters` a variable | `array_merge($filters, ['register' => ..., 'schema' => ...])`, so the scope keys win over anything the caller passed |
| `filters` a `tenantScoped(filters: [...])` call | the two keys go into its `filters:` literal |
| no `filters` | a new `filters` literal holding only the two keys |
| config in a variable | the builder writes `filters` with the two keys, and later branches add to `$config['filters'][...]` instead of replacing it |

## Read-back

- `php -l` on every touched file.
- A duplicate-key scan: the first pass left four configs with two `filters` keys (`AssessmentGradeGuard` x2, `AssessmentScoringHandler` x2), because a `// H1:` comment in front of the real `filters` entry hid it from the script. PHP keeps the last key without a warning, so these dropped the scope. Fixed by hand, and `FindAllConfigScopeTest` now flags a duplicate key.
- The full unit suite before and after: the same 14 failures in both runs, all register tests unrelated to this change.

## Test doubles

180 tests went red after the rewrite. Every one was a `findAll()` double reading `$config['schema']` (the old wrong key) or matching rows on every `filters` entry, which now includes the two scope keys. The doubles now read `$config['filters']['schema']` and drop `register` and `schema` before matching rows on the remaining filters. That is the same contract OpenRegister applies.

## Declarative-vs-imperative decision

Not applicable. The change corrects how existing imperative reads call OpenRegister; it adds no lifecycle, aggregation, calculation, notification, relation or widget.

## Seed Data

None. No schema is added or changed.

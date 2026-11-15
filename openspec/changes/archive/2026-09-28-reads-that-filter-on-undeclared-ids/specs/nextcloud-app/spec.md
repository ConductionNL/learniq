# Nextcloud App Shell: reads by id delta

**Spec refs**: `nextcloud-app`, ADR-001 (data through OpenRegister), ADR-011 (use OpenRegister core as it is)

## ADDED Requirements

### Requirement: No read filters on an object id property

No `ObjectService::findAll()` call under `lib/` MAY use `id` or `uuid` as a `filters` key, whatever schema it reads, including a schema chosen at run time. A read of one or more objects by id MUST pass the ids in the config's `ids` and MAY keep other filters, such as `tenant_id` or `lifecycle`, in `filters`. The unit-time scan MUST find the key in a literal, inside `array_merge()` or `tenantScoped()` arguments, and in a variable the filters are built in.

#### Scenario: A read by id puts the id in ids

- **GIVEN** a guard that loads Assignment `a-1` in tenant `t-1`
- **WHEN** it calls `findAll()`
- **THEN** the config carries `ids: ["a-1"]` and `filters` with `register`, `schema` and `tenant_id`, and no `id` or `uuid` key
- **AND** a store that answers like OpenRegister returns the Assignment

#### Scenario: A filter on id is refused whatever the schema

- **GIVEN** a `findAll()` whose filters carry `'id' => $id` and whose schema is a method parameter
- **WHEN** `FindAllConfigScopeTest` scans `lib/`
- **THEN** it fails and names the file, the line and the key

#### Scenario: The known list shrinks with the fix

- **GIVEN** the 29 `id` and `uuid` entries in `FindAllFilterKeysAreDeclaredTest::KNOWN_UNDECLARED`
- **WHEN** their reads move the id into `ids`
- **THEN** the entries are deleted and the test passes with the six remaining entries

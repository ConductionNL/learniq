# Nextcloud App Shell: object read scope delta

**Spec refs**: `nextcloud-app`, ADR-001 (data through OpenRegister), ADR-011 (use OpenRegister core as it is)

## ADDED Requirements

### Requirement: Every object read MUST name its register and schema inside `filters`

Every call to OpenRegister's `ObjectService::findAll()` under `lib/` MUST pass the register and the schema as `$config['filters']['register']` and `$config['filters']['schema']`. A config MUST NOT carry `register` or `schema` at its top level, whether the config is written inline or assembled in a variable. A config MUST NOT carry the same key twice. OpenRegister's `prepareFindAllConfig()` reads the scope from `filters` only, so a top-level key leaves the read without a schema or with a stale one.

#### Scenario: A guard reads a learner's rows from the right schema

- **GIVEN** a lifecycle guard that looks up the enrolments of learner `leerling-001`
- **WHEN** it calls `ObjectService::findAll()`
- **THEN** the config carries `filters.register = "learniq"` and `filters.schema = "enrolment"` next to `filters.learnerId = "leerling-001"`
- **AND** the config has no top-level `register` or `schema` key

#### Scenario: A config built in a variable is scoped the same way

- **GIVEN** a service that assembles its findAll config in `$config` before the call
- **WHEN** the config is passed to `ObjectService::findAll($config)`
- **THEN** `register` and `schema` sit under `$config['filters']`

#### Scenario: The regression test fails on the old shape

- **GIVEN** a findAll call under `lib/` with `'schema' => 'lesson'` at the top level of its config
- **WHEN** `FindAllConfigScopeTest` runs
- **THEN** it fails and names the file, the line and the key

#### Scenario: The regression test fails on a duplicate filters key

- **GIVEN** a findAll config with two `'filters'` keys
- **WHEN** `FindAllConfigScopeTest` runs
- **THEN** it fails with `duplicate filters` and the line of the second key

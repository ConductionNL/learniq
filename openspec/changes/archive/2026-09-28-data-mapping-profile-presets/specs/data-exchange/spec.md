# Data Exchange — Rostering and Migration Import Presets Delta

**Spec refs**: `data-exchange`, findings `11.7`, `13.15`

## ADDED Requirements

### Requirement: TimeEdit joins the rostering-import preset family

The system MUST ship a `DataMappingProfile` seed named `TimeEdit timetable import` for `target:
timetable-import`, `direction: import`, `sourceSchema: session`, matching the shape the existing
Zermelo/Untis/Xedule seeds already use (externalRef/cohortId/title/startsAt/endsAt/location mapped to
TimeEdit's own field names).

#### Scenario: TimeEdit matches the existing rostering-import seed shape

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the `TimeEdit timetable import` seed is loaded
- **THEN** it declares `target: timetable-import`, `direction: import`, `sourceSchema: session`, and maps
  the same field set (`externalRef`, `cohortId`, `title`, `startsAt`, `endsAt`, `location`) the
  Zermelo/Untis/Xedule seeds already map

<!-- @e2e exclude Declarative seed-data shape verified by
     DataMappingProfilePresetsRegisterTest::testTimeEditMatchesExistingRosteringSeedShape; no DOM surface —
     mirrors the existing Zermelo/Untis/Xedule seed-shape assertions. -->

### Requirement: migration-import job type and payload mappings

The system MUST support a new `target: migration-import` (`direction: import`) on `DataExchangeJob` and
`DataMappingProfile`, and MUST ship one `DataMappingProfile` seed per migration source system (ParnasSys,
ESIS, Magister, SOMtoday), each `sourceSchema: learner-profile`, mapping at minimum `eckId`, `givenName`,
`familyName`, `birthDate`, and `schoolId`.

#### Scenario: Each migration source ships its own preset carrying ECK iD

- **GIVEN** the `DataMappingProfile` seed data
- **WHEN** the ParnasSys, ESIS, Magister, and SOMtoday seeds are loaded
- **THEN** each is `target: migration-import`, `direction: import`, `sourceSchema: learner-profile`, and
  maps `eckId`

<!-- @e2e exclude Declarative seed-data shape verified by
     DataMappingProfilePresetsRegisterTest::testEachMigrationSourceCarriesEckId; no DOM surface. -->

#### Scenario: DataExchangeJob.target documents the migration-import connection

- **GIVEN** `DataExchangeJob.target`'s description
- **WHEN** this change lands
- **THEN** it names `migration-import` as a valid connection

<!-- @e2e exclude Declarative documentation-string shape verified by
     DataMappingProfilePresetsRegisterTest::testDataExchangeJobTargetDescribesMigrationImport; no DOM
     surface. -->

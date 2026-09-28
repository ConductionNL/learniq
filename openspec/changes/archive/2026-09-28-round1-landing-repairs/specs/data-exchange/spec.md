# Data exchange: connection descriptions delta

## ADDED Requirements

### Requirement: The connection field names every connection learniq hands to OpenConnector

The `target` property of `DataExchangeJob` and of `DataMappingProfile` MUST name, in its description, every connection learniq defines: `bron-rod`, `oso` (with its inbound direction), `leerplicht`, `surfconext`, `hr`, `swv`, `timetable-import`, `migration-import`, `lvs-results`, `uwlr`, `edu-v`, `basispoort` and `entree-content`, and the `DataExchangeJob` description MUST also name `ooapi-catalog`. Each description MUST have an English and a Dutch catalogue entry.

#### Scenario: A coordinator reads which connections exist
@e2e exclude Register-content invariant; pinned by tests/Unit/Settings/UwlrEduvBasispoortRegisterTest.php (testTargetDescriptionsNameNewConnections), LvsResultRegisterTest and OsoImportDossierRegisterTest.
- **GIVEN** the shipped register
- **WHEN** the `target` field of a new data exchange job is shown
- **THEN** its help text names lvs-results, the inbound oso direction, uwlr, edu-v, basispoort, entree-content and migration-import

### Requirement: Register tests find seed rows by identity and assert floors

A test that reads a schema's seed rows MUST find a row by its name or id and MUST assert a minimum count, never an exact count or a position, because other changes append seed rows to the same list.

#### Scenario: A sibling change adds a mapping preset
@e2e exclude Test-suite invariant; pinned by tests/Unit/Settings/DataMappingProfilePresetsRegisterTest.php and UwlrEduvBasispoortRegisterTest.php.
- **GIVEN** 23 `DataMappingProfile` seed rows, more than the 12 and 16 two changes counted
- **WHEN** the suite runs
- **THEN** both tests pass, because they assert a floor

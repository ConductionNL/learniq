# Data exchange: OSO import dossier without an exchange job delta

## ADDED Requirements

### Requirement: A dossier received without an exchange job is valid

`OsoImportDossier.dataExchangeJobId` MUST accept `null` for a dossier entered by hand, as `LvsResult.dataExchangeJobId` does. Every `null` in a demo object of the learniq register MUST sit on a property declared nullable.

#### Scenario: The demo dossiers pass their own schema
@e2e exclude Register declaration with no UI; pinned by tests/Unit/Register/DemoNullsAreNullableTest.php::testEveryDemoNullIsOnANullableProperty.
- **GIVEN** the demo OsoImportDossier rows with `dataExchangeJobId` null
- **WHEN** they are validated against the schema
- **THEN** they pass

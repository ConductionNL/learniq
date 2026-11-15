# Data exchange: voorlopig school advice to ROD delta

## ADDED Requirements

### Requirement: The voorlopig school advice goes to ROD when it is given

When a SchoolAdvies in `voorlopig` has `voorlopigAdviesLevel`, `voorlopigAdviesDate` and `learnerId` and no `voorlopigExchangeJobId`, learniq MUST ask integriq for a `bron-rod` export with berichtsoort `schooladvies`, the school advice mapping and the advice as its only record, outside the save that made it due, and MUST record the returned job id as `voorlopigExchangeJobId` so the voorlopig advice is sent once.

#### Scenario: A voorlopig advice goes to ROD when it is given
@e2e exclude Deferred exchange request with no UI of its own; pinned by tests/Unit/Listener/SchoolAdviesVoorlopigRodTest.php.
- **GIVEN** a SchoolAdvies in `voorlopig`
- **WHEN** its advice level and date are saved
- **THEN** a bron-rod schooladvies job is requested for it
- **AND** its id is stored as `voorlopigExchangeJobId`
- **AND** saving the advice again requests nothing

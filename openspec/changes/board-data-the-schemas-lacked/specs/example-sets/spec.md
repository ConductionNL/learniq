## ADDED Requirements

### Requirement: The board data has a place in the schemas and in the seeds

The cohort MUST offer an optional `capacity`. The placement MUST offer optional `workdaysLabel`, `workplaceAddress`, `qualificationName` and `crebo`. A student's hours and own estimate per work process MUST live in `werkproces-progress`, a sibling of `werkproces-assessment`. These additions MUST NOT change the type, format or required list of an existing property. The example sets MUST fill them from the boards, and every seeded row MUST validate against the shipped schema fragment.

#### Scenario: A fresh load carries the board's data
- **GIVEN** a fresh instance
- **WHEN** the operator loads the mbo and training sets
- **THEN** the F-gassen course date has 4 places, Milan's placement names his workdays and crebo 25743, and his six work processes carry their hours
- @e2e exclude covered by PHPUnit `BoardDataSchemaAdditionsTest` (the real seed payloads against the real schema fragments) and the generator checks (`scripts/example-sets/*.py --check`)

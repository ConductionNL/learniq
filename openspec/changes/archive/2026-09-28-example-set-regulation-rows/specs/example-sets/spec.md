# Example Sets Specification

## ADDED Requirements

### Requirement: A schema with its own slug pattern takes the slug from the object
When a learniq schema declares a `pattern` on its own `slug` property, an example object of that schema MUST use its own `slug` as the envelope slug: the value MUST match the schema's pattern and MUST be unique within the descriptor, and the `<id>-<schema>-<NNN>` form MUST NOT be required of it. Every other contract rule, including the fixed `uuid` inside the set's namespace, MUST still apply. For a schema whose `slug` property has no pattern, or that has no `slug` property, the `<id>-<schema>-<NNN>` form MUST still apply. `ExampleSetDescriptorContractTest` MUST enforce both branches.

#### Scenario: A Regulation row with its code as slug passes
- **GIVEN** a descriptor with a `regulation` object whose `slug` is `VCA` and whose `uuid` is inside the set's namespace
- **WHEN** the contract test runs
- **THEN** it reports no finding for that object

#### Scenario: A Regulation row with the envelope form fails the pattern
- **GIVEN** a `regulation` object whose `slug` is `corporate-regulation-001`
- **WHEN** the contract test runs
- **THEN** it reports that `slug` does not match `^[A-Z0-9_-]+$`

#### Scenario: Two rows with the same code
- **GIVEN** two `regulation` objects whose `slug` is `VCA`
- **WHEN** the contract test runs
- **THEN** it reports the second one as not unique

### Requirement: A set does not re-ship a row the register seeds
An example object of a schema with its own slug pattern MUST NOT reuse a `slug` that `learniq_register.json` already seeds for that schema, because the importer matches seed objects by `uuid` and would create a second row with the same identifier. The contract test MUST report such an object.

#### Scenario: A set that ships AVG
- **GIVEN** the register seeds the regulation `AVG`
- **WHEN** a descriptor ships a `regulation` object with `slug` `AVG`
- **THEN** the contract test reports that the register already seeds it

### Requirement: The company and training sets carry the regulations they reference
The company set MUST ship a published, active Regulation row for every `regulationSlug` its objects carry, except the ones the register seeds, and so MUST the training set. Each row's audience MUST describe who that set actually trains: for the company, `department` scopes for VCA (`Operatie`), NEN 3140 (`Operatie/Installatie en service`, `Operatie/Werkplaats`) and the forklift certificate (`Operatie/Magazijn en logistiek`); `all-employees` for the code of conduct and information security; `board` with the `manager` and `compliance-officer` roles for NIS2; and an empty `role-specific` audience for BHV and F-gassen, whose obligation falls on designated people. The training institute obliges none of its participants, so its rows carry an empty `role-specific` audience and describe the certificate they lead to. `profile.objectCount` MUST equal the real count.

#### Scenario: Every company regulation reference resolves
- **GIVEN** the company set
- **WHEN** every `regulationSlug` in it is collected
- **THEN** each one is the `slug` of a Regulation row in the set, or `AVG`

#### Scenario: The company scopes drive the certification check
- **GIVEN** the company set's Regulation rows with a `department` audience
- **WHEN** `CorporateExampleSetTest` checks that everyone in scope holds the certificate or is booked on it
- **THEN** it reads the scopes from those rows, and they include VCA, NEN 3140 and the forklift certificate

#### Scenario: Every training regulation reference resolves
- **GIVEN** the training set
- **WHEN** every `regulationSlug` in it is collected
- **THEN** each one is the `slug` of a Regulation row in the set, or `AVG`

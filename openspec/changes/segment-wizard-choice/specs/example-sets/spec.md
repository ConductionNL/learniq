# Example sets Specification

## ADDED Requirements

### Requirement: An example set is one descriptor file per segment
Each example set MUST be one file `lib/Settings/profiles/<id>.json`, where `<id>` is one of the six segment codes (`po`, `vo`, `mbo`, `he`, `corporate`, `training`) and equals `x-openregister.profile.id` and `x-openregister.profile.segment`. The descriptor MUST declare `x-openregister.type: profile`, MUST NOT declare `components.registers` or `components.schemas`, and MUST carry its objects in `x-openregister.seedData.objects`, keyed by learniq schema slug. Every object MUST carry `@self` with `configuration`, `register` (`learniq`) and `schema` (the bucket key), a fixed `uuid` inside the set's namespace, and a `slug`. Every property holding a reference to another object MUST hold the fixed `uuid` of an object in the same set, or null. A set MUST NOT carry `LearniqSettings` objects.

#### Scenario: A descriptor that follows the contract passes the contract test
- **GIVEN** a file `lib/Settings/profiles/po.json` that follows the contract
- **WHEN** `ExampleSetDescriptorContractTest` runs
- **THEN** every check passes: keys, id, namespace, unique uuids, resolved references, declared object count, schema-valid objects

#### Scenario: A dangling reference fails the contract test
- **GIVEN** a set whose `Enrolment.cohortId` names a uuid no object in the set carries
- **WHEN** the contract test runs
- **THEN** it fails and names the object, the property and the missing uuid

### Requirement: The wizard lists the shipped sets next to the generated one
`SeedProfileService::listChoices()` MUST return `none` first, then every readable descriptor in `order`, then the generated set (`demo`) when `learniq_mock_register.json` ships. The setup status document MUST carry that list as `profiles`, and the `example-set` choice step MUST read it through `optionsSource: profiles`. A malformed descriptor MUST be skipped with a log line, never make the other sets unreachable.

#### Scenario: Two sets on disk
- **GIVEN** `po.json` (order 1) and `vo.json` (order 2) under `lib/Settings/profiles/`
- **WHEN** the setup status is requested
- **THEN** `profiles` lists `none`, `po`, `vo`, `demo` in that order

#### Scenario: A broken file does not hide the others
- **GIVEN** `vo.json` is not valid JSON
- **WHEN** the sets are listed
- **THEN** `po` and `demo` are still listed and a warning is logged

### Requirement: Loading a set imports exactly its descriptor
The `load-example-set` action MUST import the set stored in `example_profile` through OpenRegister's `ConfigurationService::importFromApp()` with the config id `learniq.profile.<id>`, report the object count it asked for, and answer `none` as a finished decision. An unknown or missing answer MUST be refused, never replaced by a default. The id MUST be resolved by reading the files, never by building a path from the request.

#### Scenario: Loading the primary school set
- **GIVEN** `example_profile` is `po`
- **WHEN** `load-example-set` runs
- **THEN** `importFromApp` receives the `po` descriptor under config id `learniq.profile.po`
- **AND** the answer names the object count

#### Scenario: A path in the answer is refused
- **GIVEN** a request posting `example_profile: "../../config/config"`
- **WHEN** the answer is saved
- **THEN** it is refused with HTTP 400

### Requirement: A loaded set can be removed exactly
`occ learniq:example-set:remove <id>` MUST hand exactly the fixed uuids that `<id>.json` declares, in reverse load order, to OpenRegister's `openregister:objects:purge` with `--force`, and MUST pass `--apply` only when it was given `--apply` itself (dry run by default). It MUST NOT delete anything itself and MUST NOT offer an HTTP route: three school schemas are archival, and OpenRegister keeps the shell as the one deliberate exit for them. The generated set has no fixed uuids and MUST be refused with an explanation, as MUST an unknown id and an instance without OpenRegister's purge command.

#### Scenario: Removing the primary school set
- **GIVEN** the `po` set was loaded
- **WHEN** `occ learniq:example-set:remove po --apply` runs
- **THEN** `openregister:objects:purge` receives every uuid in `po.json`, last-loaded first, with `--force` and `--apply`
- **AND** no other uuid

#### Scenario: A dry run changes nothing
- **GIVEN** the `po` set was loaded
- **WHEN** `occ learniq:example-set:remove po` runs without `--apply`
- **THEN** the purge command runs without `--apply` and only reports what it would purge

#### Scenario: The generated set is refused
- **GIVEN** the generated set `demo`
- **WHEN** `occ learniq:example-set:remove demo` runs
- **THEN** it exits non-zero and explains that only sets with fixed uuids can be removed

### Requirement: The wizard asks what kind of organisation this is
The setup wizard MUST offer a `segment` choice step titled "What kind of organisation is this?" with six single-select cards from the status document's `segments` list: primary school, secondary school, MBO, HBO/WO, company, training institute. Saving it MUST write `LearniqSettings.segment` (creating the record when none exists, otherwise updating the current one, stamping `setBy` and `setAt`). The step MUST report done once a valid segment is stored, and MUST pre-select the segment of the example set picked earlier. `welcome` MUST stay step 1 and `example-set` step 2.

#### Scenario: A school picks primary school
- **GIVEN** no `LearniqSettings` record exists
- **WHEN** the admin picks "Primary school" and continues
- **THEN** a `LearniqSettings` record with `segment: po`, `setBy` the admin's user id and `setAt` now is created
- **AND** the status reports the `segment` step done

#### Scenario: The primary school example set pre-selects primary school
- **GIVEN** the admin picked the `po` example set
- **WHEN** the `segment` step opens
- **THEN** "Primary school" is pre-selected

#### Scenario: An unknown segment is refused
- **GIVEN** a request posting `segment: "kindergarten"`
- **WHEN** the answer is saved
- **THEN** it is refused with HTTP 400 and nothing is written

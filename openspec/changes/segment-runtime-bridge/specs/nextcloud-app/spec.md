# Nextcloud App Specification

## ADDED Requirements

### Requirement: LearniqSettings knows six organisation kinds
`LearniqSettings.segment` MUST accept exactly `po`, `vo`, `mbo`, `he`, `corporate` and `training`, default `corporate`, and MUST declare an `x-enum-labels` entry for each value so forms and filters show a label instead of the stored code. Every label MUST have an English and a Dutch catalogue key.

#### Scenario: A training institute can record its kind
- **GIVEN** the `LearniqSettings` schema
- **WHEN** an administrator sets `segment` to `training`
- **THEN** the value validates against the enum
- **AND** the form shows "Training institute" (Dutch: "Opleidingsinstituut")

#### Scenario: The code lists and the schema agree
- **GIVEN** the segment list in `SegmentService`, the one in `src/utils/workspaceRuntime.js` and the schema enum
- **WHEN** the unit tests compare them
- **THEN** all three hold the same six values in the same order

### Requirement: The server resolves one current segment
`SegmentService::currentSegment()` MUST return the segment of the most recently updated `LearniqSettings` row whose value is one of the six known codes. It MUST return `corporate` when no such row exists, when the read throws, or when every row carries an unknown value. The read MUST NOT apply RBAC, because every signed-in user's menu depends on it and the segment code is not personal data.

#### Scenario: No settings record yet
- **GIVEN** no `LearniqSettings` object exists
- **WHEN** the current segment is resolved
- **THEN** it is `corporate`

#### Scenario: Several rows, the newest wins
- **GIVEN** a `corporate` row updated on 1 March and a `po` row updated on 27 September
- **WHEN** the current segment is resolved
- **THEN** it is `po`

#### Scenario: A failed read does not break the page
- **GIVEN** the OpenRegister read throws
- **WHEN** the current segment is resolved
- **THEN** it is `corporate` and the failure is logged

### Requirement: The segment reaches the manifest runtime
`PageController::index()` MUST provide the resolved segment as initial state `segment` for a signed-in user, and `src/main.js` MUST publish it at `manifest.runtime.workspace.segment` before the manifest is built, so a menu `visibleIf` on `workspace.segment` resolves against a defined value. A missing or unknown value MUST become `corporate` in the browser too.

#### Scenario: A signed-in user's page carries the segment
- **GIVEN** the instance segment is `po`
- **WHEN** a signed-in user opens the app
- **THEN** the page provides initial state `segment` with value `po`
- **AND** `manifest.runtime.workspace.segment` is `po`

#### Scenario: A missing initial state falls back safely
- **GIVEN** the page provides no `segment` initial state
- **WHEN** the runtime is built
- **THEN** `manifest.runtime.workspace.segment` is `corporate`

### Requirement: Generic demo data does not change the segment
The generated demo register MUST NOT carry a `LearniqSettings` row with a segment other than `corporate`, so loading the generic example data never changes which menus an instance shows.

#### Scenario: Loading the generic demo data
- **GIVEN** the demo register `lib/Settings/learniq_mock_register.json`
- **WHEN** its `LearniqSettings` rows are read
- **THEN** every row carries `segment: corporate`

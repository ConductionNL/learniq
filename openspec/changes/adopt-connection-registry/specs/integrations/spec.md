# integrations Specification Delta

**Status**: proposed
**Scope**: learniq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see learniq's outside connections on one page, with a status the app can back.

## ADDED Requirements

### Requirement: REQ-INT-CONN-001 Learniq declares its outside connections in one static file

Learniq SHALL declare its outside connections in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The file SHALL declare `data-exchange`, `timetable`, `lti`, `eudi-wallet`, `payment`, `sbb`, `proctoring` and `plagiarism`. A connection whose call goes to an endpoint integriq does not publish, or whose provider does not exist, SHALL be declared not available with a message that says why. `eudi-wallet` SHALL require `openconnector_api_token`. Every `settingsUrl` SHALL point at a section id that exists on the admin page.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php checks the shape, the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique

#### Scenario: A connection to a missing endpoint reads Not available and names the path
@e2e tests/e2e/connection-registry.spec.ts

- **GIVEN** integriq has synced learniq's declaration
- **WHEN** an admin reads the Data exchange row
- **THEN** it SHALL read Not available
- **AND** its message SHALL name `api/sources/[target]/run`
- **AND** it SHALL offer Open settings on the data exchange section

#### Scenario: A changed call path turns the declaration test red
@e2e exclude The guard reads source constants; tests/Unit/Settings/ConnectionsDeclarationTest.php compares each unavailable message with the path the code calls.

- **GIVEN** a change points `PaymentInitiationClient` at another endpoint
- **WHEN** the unit tests run
- **THEN** the declaration test SHALL fail on the payment row

### Requirement: REQ-INT-CONN-002 Learniq reports what the last wallet offer met

Learniq SHALL record the outcome of every EUDI wallet offer as `configured`, `unconfigured` or `error`, writing config only when the outcome changes. Learniq SHALL send the recorded outcome with `ConnectionStatusReportedEvent` once a day and after every settings save, never per request and never from inside the offer's lifecycle guard. Without integriq, learniq SHALL send nothing and log nothing, and SHALL keep the outcome.

#### Scenario: A refused offer reaches the wallet row
@e2e exclude A wallet offer needs a signed credential and an integriq issuer, which the CI instance does not seed; tests/Unit/Service/WalletOfferDelegationServiceTest.php and ConnectionReportServiceTest.php cover the record and the report.

- **GIVEN** integriq answers a wallet offer with an error
- **WHEN** the daily report runs
- **THEN** learniq SHALL send a report for `eudi-wallet` with status `error`
- **AND** its message SHALL say since when the outcome holds

#### Scenario: A run of offers with the same outcome writes config once
@e2e exclude Write counts are not observable from a browser; tests/Unit/Service/ConnectionReportServiceTest.php asserts one write for 25 equal outcomes.

- **GIVEN** 25 wallet offers that all reach integriq
- **WHEN** each records its outcome
- **THEN** app config SHALL be written once

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/ConnectionReportServiceTest.php and SettingsControllerConnectionReportTest.php cover the absent class and the unchanged save.

- **GIVEN** integriq is not installed
- **WHEN** an admin saves the settings
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the save SHALL answer as before

### Requirement: REQ-INT-CONN-003 An admin reads learniq's connections on an Integrations page

Learniq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `learniq` through its menu entry's `query` (hydra REQ-CONN-006). The menu entry SHALL render only for admins, and only when integriq is installed. The page SHALL require Integriq. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=learniq&link=1`.

#### Scenario: The page lists only learniq's rows
@e2e tests/e2e/connection-registry.spec.ts

- **GIVEN** learniq and integriq are installed and integriq has synced learniq's declaration
- **WHEN** an admin opens the Integrations page from the settings gear
- **THEN** the page SHALL list the eight declared connections
- **AND** every listed row SHALL have `app` equal to `learniq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/connection-registry.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=learniq` and `link=1`

#### Scenario: A status renders as a word
@e2e exclude The formatter is a pure function and every value is asserted in tests/unit-js/connectionRegistry.test.mjs; a browser run adds nothing it can observe.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited

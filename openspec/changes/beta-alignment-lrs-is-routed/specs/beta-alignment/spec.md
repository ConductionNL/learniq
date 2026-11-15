# beta-alignment

## REMOVED Requirements

### Requirement: Public surfaces SHALL only claim shipped capabilities as live

**Reason**: its cmi5/xAPI scenario says no LRS route exists, which stopped being true on 30 Sep.
**Migration**: replaced by "Public surfaces SHALL claim only what ships as live" below, with the
same rule and the cmi5/xAPI scenario rewritten to the routes that ship.

## ADDED Requirements

### Requirement: Public surfaces SHALL claim only what ships as live

Learniq's `appinfo/info.xml` description, the conduction.nl product page (EN + NL), and the
`scholiq.conduction.nl` docs home page (`docs/intro.md`) SHALL describe a feature as currently working only
when a routed controller (or equivalent OR-declarative mechanism) exists at HEAD. Planned or partially-built
capabilities (e.g. a schema plus a lifecycle hook with no reachable HTTP entry point) SHALL be labelled as
roadmap/in-progress, not as live.

#### Scenario: cmi5/xAPI LRS ingest and cmi5 launch are claimed as shipped

- **GIVEN** `appinfo/routes.php` routes `POST` and `GET /api/lrs/statements` (`LrsController`), the xAPI
  State and Agent Profile documents (`LrsDocumentController`), the cmi5 launch and fetch
  (`Cmi5LaunchController`) and the admin launch key (`Cmi5KeyAdminController`)
- **AND** `Cmi5LaunchTokenService::isEnabled()` returns true once an admin has generated the launch key
- **WHEN** a public surface (info.xml, product page, or docs intro) describes the cmi5/xAPI content runtime
- **THEN** it SHALL describe xAPI statement ingest and cmi5 launch as working features
- **AND** it SHALL say that an admin generates the launch key before the first cmi5 launch

#### Scenario: QTI import and Open Badges 3.0 verification are claimed as shipped

- **GIVEN** `POST /api/assessment/qti-import` (`QtiImportController`) and `GET /api/credentials/{id}/verify`
  (`CredentialVerifyController`) are both routed and implemented
- **WHEN** a public surface enumerates Learniq's v0.1 feature set
- **THEN** it SHALL list QTI 2.x/3.0 (+ Common Cartridge) item-bank import and Open Badges 3.0 credential
  verification as shipped capabilities, not as a later phase

#### Scenario: Dutch data-exchange protocols are described as roadmap, not native integration

- **GIVEN** no OpenConnector adapter exists for BRON/ROD, OSO, or SURFconext wire protocols, and Learniq's
  `DataExchangeJob` mechanism delegates all protocol logic to OpenConnector
- **WHEN** a public surface describes Learniq's relationship to DUO BRON/ROD, OSO transfer, or SURFconext
  federation
- **THEN** it SHALL describe these as roadmap integrations dependent on a future OpenConnector adapter, not
  as something Learniq "connects natively to" today

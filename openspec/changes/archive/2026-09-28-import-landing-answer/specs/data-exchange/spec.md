# Data exchange: import landing delta

## ADDED Requirements

### Requirement: Records integriq hands back for an import land in learniq

When integriq dispatches `ExchangeRecordsReceivedEvent` for a job owned by `learniq` with target `lvs-results`, `oso` or `migration-import`, learniq MUST land each record and MUST call `accept()` exactly once, with the number of records taken and a rejection per record it did not take, naming an error code and field names but never a value. An LVS result MUST land as an `imported` LvsResult and MUST NOT change a verified or archived one; an OSO dossier MUST land as a `received` OsoImportDossier held for review; a migration record MUST fill only empty fields of the pupil's LearnerProfile, or create an active profile. A second delivery of the same record MUST change nothing already taken. Events for another owner or target MUST be left unanswered.

#### Scenario: An LVS result lands as imported
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testLvsResultsLandAsImported.
- **GIVEN** an lvs-results import job with three records, one for a known pupil, one for an unknown pupil and one without instrument and moment
- **WHEN** integriq hands the records to learniq
- **THEN** one LvsResult is stored in `imported` with the job's id
- **AND** learniq accepts 1 and rejects `LVS-UNKNOWN-PUPIL` and `LVS-MISSING-FIELD` with the field names

#### Scenario: A second delivery changes nothing already taken
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testASecondDeliveryIsIdempotent.
- **GIVEN** a verified LvsResult
- **WHEN** the same record is delivered again with another score
- **THEN** nothing is written and the record counts as taken

#### Scenario: An OSO dossier is held for review
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testAnOsoDossierIsHeldForReview.
- **GIVEN** an oso import job with a dossier from school 12AB
- **WHEN** integriq hands it to learniq
- **THEN** an OsoImportDossier is stored in `received`

#### Scenario: A migrated pupil fills only the gaps of their profile
@e2e exclude Background job hand-off with no UI; pinned by tests/Unit/Listener/ExchangeImportLandingListenerTest.php::testMigrationFillsOnlyTheGaps.
- **GIVEN** a LearnerProfile with a first name and no family name
- **WHEN** a migration record brings another first name, a family name and roles
- **THEN** only the family name is written

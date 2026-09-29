# Data exchange: import records in the gate answer delta

## ADDED Requirements

### Requirement: The gate hands the rows of an import job's file to integriq

When the gate allows a `lvs-results`, `oso` or `migration-import` import job, learniq MUST answer with the rows of the file the job names in `scope.fileId`, each as `{recordId, sourceKind, data}` with `recordId` `<fileId>:<row number>`. The file MUST be looked up only among the files of the person who asked for the job. A job without a file, a file the requester cannot open, a file over 10 MB or 5,000 rows, or a file that cannot be read as CSV, JSON or XML MUST be refused with a code. The gate MUST NOT log file content or rows. Other import targets MUST be allowed without records.

#### Scenario: An LVS results file is handed over row by row
@e2e exclude Background gate answer with no UI; pinned by tests/Unit/Service/ExchangeGateServiceTest.php::testAnLvsImportHandsOverTheRowsOfItsFile.
- **GIVEN** an lvs-results import job whose scope names a CSV file with two rows, asked for by a person who can open it
- **WHEN** integriq asks the gate
- **THEN** the gate allows the job with two records, `<fileId>:1` and `<fileId>:2`, carrying the header names as fields

#### Scenario: A job without a file is refused
@e2e exclude Background gate answer with no UI; pinned by tests/Unit/Service/ExchangeGateServiceTest.php::testAnImportWithoutAFileIsRefused.
- **GIVEN** a migration-import job with no `fileId` in its scope
- **WHEN** integriq asks the gate
- **THEN** the gate refuses it with `import-input-missing`

#### Scenario: A file the requester cannot open is refused
@e2e exclude Background gate answer with no UI; pinned by tests/Unit/Service/ExchangeImportInputTest.php::testAFileOutsideTheRequestersFilesIsRefused.
- **GIVEN** an oso import job naming a file that is not among the requester's files
- **WHEN** integriq asks the gate
- **THEN** the gate refuses it with `import-input-unreadable`

#### Scenario: A file over the bound is refused before it is read
@e2e exclude Background gate answer with no UI; pinned by tests/Unit/Service/ExchangeImportInputTest.php::testAFileOverTheBoundIsNotRead.
- **GIVEN** a file larger than 10 MB
- **WHEN** the gate reads the job's input
- **THEN** it refuses with `import-input-too-large` without reading the content

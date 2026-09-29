## ADDED Requirements

### Requirement: Spreadsheet import of external training

The system MUST let a compliance officer upload a CSV or XLSX file of external training records and MUST show a preview of every row, marked ready, unmatched or invalid with the reason, before anything is stored. Confirming MUST create one `ExternalTrainingRecord` for each ready row and MUST leave the other rows untouched. Learners MUST be matched by learner reference or email within the caller's tenant only.

#### Scenario: An officer uploads a provider's attendance list

- **GIVEN** a compliance officer on /compliance/external-training with a CSV of 40 rows of which 3 have an unknown email
- **WHEN** the officer uploads the file and reviews the preview
- **THEN** the preview shows 37 ready rows and 3 unmatched rows with the reason, and confirming creates 37 records

#### Scenario: An invalid date is refused per row

- **GIVEN** a CSV row whose completed on date is in the future
- **WHEN** the file is previewed
- **THEN** that row is marked invalid with the reason and the other rows are still ready

#### Scenario: A learner cannot upload

- **GIVEN** a learner without the compliance officer role
- **WHEN** the learner posts to the import route
- **THEN** the server answers 403

### Requirement: Import result report

The system MUST return, after an import, a count of created, skipped and failed rows and MUST offer the failed rows as a downloadable file that can be corrected and uploaded again.

#### Scenario: The officer fixes the failed rows

- **GIVEN** an import in which two rows failed
- **WHEN** the officer downloads the failed rows, corrects them and uploads that file
- **THEN** the two rows are created and no earlier row is duplicated

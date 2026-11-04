# external-training-upload Specification

## Purpose
A compliance officer records outside training for many people at once by uploading a provider's attendance list as CSV. Each row is matched to a learner of the officer's own organisation by email, learner reference or personal number, checked before anything is stored, and recorded in one batch that waits for verification.

## Requirements

### Requirement: Spreadsheet import of external training

The system MUST let a compliance officer upload a CSV file of external training records (a spreadsheet saved as CSV) and MUST store every valid row straight away and show the result of the upload ("Resultaat van de upload", learniq/LqCfTrainingVastleggen) per row: stored, unmatched or invalid with the reason. Nothing is stored. Confirming MUST create one `ExternalTrainingRecord` for each ready row and MUST leave the other rows untouched. Learners MUST be matched by learner reference or email within the caller's tenant only.

#### Scenario: An officer uploads a provider's attendance list

- **GIVEN** a compliance officer on /compliance/external-training with a CSV of 40 rows of which 3 have an unknown email
- **WHEN** the officer uploads the file
- **THEN** 37 records are created and the result shows 37 stored rows and 3 unmatched rows with the reason

#### Scenario: An invalid date is refused per row

- **GIVEN** a CSV row whose completed on date is in the future
- **WHEN** the file is uploaded
- **THEN** that row is marked invalid with the reason and the other rows are still stored

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

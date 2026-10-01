# Design: upload a spreadsheet of outside training for many people at once

## Context

At development `acdf1dd5`:

- `src/views/ExternalTrainingBulkRecordView.vue` collects `learners`, `training.completedAt`, `validUntil`, `regulationSlug`, `evidenceNote` and posts once.
- `lib/Controller/ExternalTrainingController.php:98` `bulkRecord(array $learnerIds, array $training)`; `:145` `issueCredential`; `:260` `learnerCoverage`.
- `lib/Settings/learniq_register.json` `ExternalTrainingRecord` has `learnerId`, `learnerUserId`, `learnerRef`, `title`, `provider`, `kind`, `regulationSlug`, `completedAt`, `validUntil`, `evidenceNote`, `batchId`.
- `batchId` already groups the records of one bulk action and is reused for one upload.
- Matrix defect learniq#952: nothing in `src/` called the bulk route; that was fixed by the view above and is not repeated here.

## Goals / Non-Goals

**Goals**
- A file of outside training becomes records with a preview and a per-row result.

**Non-Goals**
- Uploading evidence files per row.
- A provider portal that pushes attendance lists.

## Decisions

### D1: Parse in the browser, validate on the server

The browser parses the file (CSV natively, XLSX with the library the app already ships for exports if any, else CSV only) and posts JSON rows, so the server never handles a file format and every row passes through one validator.

### D2: Dry run is a flag, not a second route

The import route takes `dryRun: true` and returns the same per-row report without writing, so preview and import cannot disagree.

### D3: CSV only, Dutch and English headings

The app ships no spreadsheet library, so the upload reads CSV, the format every spreadsheet saves to (D1's fallback). The spec's "CSV or XLSX" is narrowed to CSV in this change. The reader takes a comma or, as a Dutch spreadsheet saves it, a semicolon, and maps English and Dutch headings (`learner`/`deelnemer`, `completed on`/`afgerond op`, and so on) to the row keys. A heading it does not know is named and ignored.

### D4: Matching, in the caller's tenant only

The server resolves the tenant through `CallerTenantResolver`; a tenant in the posted rows is ignored. The learner column is an email address (the Nextcloud account with that address, then its LearnerProfile by `ncUserId`), a LearnerProfile uuid, or a `personalNumber`. Each candidate's `tenant_id` and the matched property are checked on the row as well as in the query, so a filter the store does not apply cannot widen the match. Two learners for one email is reported, never guessed.

### D5: Row checks and duplicates

Title, provider and completed on are required; completed on may not be in the future; valid until must come after it; kind is one of the schema's values, classroom when empty. Dates are stored as UTC midnight in the schema's date-time format. A row for the same learner, title and date as an earlier row is `duplicate`; one already recorded is `skipped`. That is what makes uploading the corrected failed rows safe: nothing earlier is created twice. A reason with a variable part carries `{placeholders}` and `reasonParams`, so the dialog translates it.

### D6: Where it lives

`ExternalTrainingImport` is its own service next to `ExternalTrainingService`, because it needs the user manager and its own matching; the route reuses the `external-training.bulk-record` action (admin, compliance officer, HR). The upload is opened from the group recording page (`ExternalTrainingBulkRecordView`), which the External training index already links to; after an import the page loads the new batch, so verifying and issuing credentials work as for a group entry. At most 1000 rows per file.

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

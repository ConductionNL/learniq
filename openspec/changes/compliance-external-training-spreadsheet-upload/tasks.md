# Tasks: upload a spreadsheet of outside training for many people at once

## 1. Server

- [x] 1.1 Add the per-row validator and match rules (reference, then email, tenant scoped) to the external training service. Verify: PHPUnit for ready, unmatched, invalid date, duplicate row.
- [x] 1.2 Add `POST /api/external-training/import` with `dryRun` and the `batchId` grouping. Verify: PHPUnit on the controller; hydra gates 5, 7 and 30.

## 2. Frontend

- [x] 2.1a Add the upload dialog with preview and result report and the failed-rows download. Verify: node --test `tests/unit-js/externalTrainingUpload.test.mjs` for reading the file, the preview counts and the failed-rows file.
- [ ] 2.1b Playwright flow: upload a fixture CSV, confirm, see the records (live check, owed).

## 3. Close out

- [x] 3.1 Add strings to every shipped locale through the writing skill. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `comp-external-bulk-upload` to built and archive the change. Verify: parity_verify --strict.


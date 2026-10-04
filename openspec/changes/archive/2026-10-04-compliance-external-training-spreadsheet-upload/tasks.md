# Tasks: upload a spreadsheet of outside training for many people at once

## 1. Server

- [x] 1.1 Add the per-row validator and match rules (reference, then email, tenant scoped) to the external training service. Verify: PHPUnit for ready, unmatched, invalid date, duplicate row.
- [x] 1.2 Add `POST /api/external-training/import` with `dryRun` and the `batchId` grouping. Verify: PHPUnit on the controller; hydra gates 5, 7 and 30.
- [x] 1.3 Live pass D6 (2 Oct, DECISIONS row 54): `LearnerProfile.personalNumber` is no longer `x-openregister-encrypted` (the instance stored no value and OpenRegister rejects a filter on an encrypted property), so a row matches on it. Register 0.34.33. Verify: `PersonalNumberMatchTest` (stored, read back, matched, through a store that treats an encrypted property as OpenRegister does; payload validated with Opis), `PersonalNumberProtectionTest`. Red before the code: `~/memcap-work/build-all/learniq/lanefix2/d6-red.log`.

## 2. Frontend

- [x] 2.1a Add the upload dialog with preview and result report and the failed-rows download. Verify: node --test `tests/unit-js/externalTrainingUpload.test.mjs` for reading the file, the preview counts and the failed-rows file.
- [x] 2.1b Playwright flow: upload a fixture CSV, confirm, see the records (live check, owed). Done live on the dev instance 2026-10-04 (learniq ef8d9b80, register re-imported by `occ upgrade` to 0.3.8-beta, OpenRegister with #4270): a new livepass learner profile in the officer's tenant got personalNumber LPL8-0001 by PATCH (200), GET reads it back and the `personal_number` column holds it. As lp-officer2 (compliance-officers), /compliance/external-training/record-for-group > Upload a spreadsheet with a two-row CSV: the dry run reads "1 ready, 0 already recorded, 1 cannot be recorded" (row 1 matched by personal number, row 2 "No learner in your organisation has this reference."); "Record 1 rows" creates one record in batch 4438752c (state submitted), and /compliance/external-training lists it. Evidence: ~/memcap-work/build-all/livepass/learniq/compliance-external-training-spreadsheet-upload/lane8/ and RESULT-lane8.md.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale through the writing skill. Verify: `npm run test:l10n`.
- [x] 3.2 Set row `comp-external-bulk-upload` to built and archive the change. Verify: parity_verify --strict.


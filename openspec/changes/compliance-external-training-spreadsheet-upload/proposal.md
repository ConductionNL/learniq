---
kind: code
---

# Upload a spreadsheet of outside training for many people at once

## Why

A compliance officer who receives an attendance list from a training provider has to pick the learners one by one in `src/views/ExternalTrainingBulkRecordView.vue`. The endpoint `externalTraining#bulkRecord` (`appinfo/routes.php:147`, `lib/Controller/ExternalTrainingController.php:98`) takes `learnerIds` and one `training` object, so one training for many people is built; a spreadsheet with a row per person and a training per row is not. Compliance is the core area (the first 30 rows are compliance and credentials) and Totara rates yes.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `comp-external-bulk-upload` | Upload a spreadsheet of outside training for many people at once. | `partial`: `partial`: `POST /api/external-training/bulk` records one training for picked learners; nothing reads a spreadsheet |

### Demand

- `comp-external-bulk-upload`: no demand row.

### Competitors rated yes

- `comp-external-bulk-upload`, totara: "read 2026-09-26: https://totara.help/docs/upload-course-completions; CSV import of historical completion records, and 'if a course doesn't exist in the system, the platform can automatically generate evidence items in the Record o"

## What Changes

- Add an Upload spreadsheet action to the external training page: a CSV or XLSX with one row per person (learner id or email, title, provider, completed on, valid until, regulation, evidence note).
- Show a dry-run preview that lists each row as ready, matched to a learner, or rejected with the reason, and record only the ready rows on confirm.
- Add `POST /api/external-training/import` that accepts the parsed rows, validates each one with the same rules as a single record, and returns per-row results.

## Capabilities

### New Capabilities

- `external-training-upload`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `ExternalTrainingBulkRecordView.vue` (or a new upload dialog file), the external training page header actions.
- **Backend**: `ExternalTrainingController::import`, a row validation method on the existing external training service; route in `appinfo/routes.php`.
- **Database**: none; register unchanged.
- **Security**: route needs the compliance officer or admin role; tenant scoping on the learner match; hydra route-auth, IDOR and semantic-auth gates.

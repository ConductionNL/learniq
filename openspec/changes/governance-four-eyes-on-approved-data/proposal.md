---
kind: code
---

# Stop one person changing data that has already been approved

## Why

GLR requirement 206600 (TenderNed) asks for authorisations applied and the four-eyes principle, so approved data cannot be changed by one user. `ReportPeriodLockGuard` (`lib/Lifecycle/ReportPeriodLockGuard.php`) blocks grade publishing after a report period is locked, but it lets a user with the admin, mentor or principal role override alone, which is exactly one person changing approved data. Moodle, ILIAS and Totara rate partial. The row is a tender demand row, so it is built.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `gov-four-eyes-on-approved-data` | Stop one person changing data that has already been approved. | `partial`: `partial`: a locked report period blocks teacher grade publishing, but an admin, mentor or principal can override alone and nothing records a second approver |

### Demand

- `gov-four-eyes-on-approved-data`: tender, https://www.tenderned.nl/aankondigingen/overzicht/415112

### Competitors rated yes

- `gov-four-eyes-on-approved-data`: no competitor rated yes.

## What Changes

- Add a `DataCorrectionRequest` schema: the approved object, the proposed change, the reason, the requester, the approver, lifecycle `requested`, `approved`, `rejected`, `applied`.
- Change the override in `ReportPeriodLockGuard`: a post-lock publish needs an `approved` correction request for that grade entry, decided by a user other than the requester.
- Add a corrections page listing requests for approvers.

## Capabilities

### New Capabilities

- `governance-four-eyes`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: new schema `DataCorrectionRequest`; the guard reference on `GradeEntry` stays.
- **Backend**: `ReportPeriodLockGuard` (composes `FraudCaseBlockGuard`; that stays), a new correction guard.
- **Frontend**: corrections page and a request action on locked entries.
- **Scope**: grades under a locked report period only in this change; other approved objects are listed as follow-ups in the design.

# Tasks: stop one person changing data that has already been approved

## 1. Register and guard

- [ ] 1.1 Add `DataCorrectionRequest` with its lifecycle and a decision guard (approver differs from requester). Verify: `npm run check:register`, PHPUnit for approve, self-approve and reject.
- [ ] 1.2 Change `ReportPeriodLockGuard` to require an approved request for the entry and to keep the fraud check byte for byte. Verify: PHPUnit for the four cases in the spec; a wiring test from the GradeEntry schema.

## 2. UI

- [ ] 2.1 Add the corrections page and the request action on locked entries. Verify: Playwright flow request, approve as another user, republish.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `gov-four-eyes-on-approved-data` to built and archive the change. Verify: parity_verify --strict.


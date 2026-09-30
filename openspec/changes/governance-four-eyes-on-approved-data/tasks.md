# Tasks: stop one person changing data that has already been approved

## 1. Register and guard

- [x] 1.1 Add `DataCorrectionRequest` with its lifecycle and a decision guard (approver differs from requester). Verify: `npm run check:register`, PHPUnit for approve, self-approve and reject.
  - `DataCorrectionDecisionGuardTest` (3), `DataCorrectionRequestStampTest` (3), `DataCorrectionRequestRegisterTest` (7: transitions and their groups, read rule, notification, the written payloads and the demo row against the shipped schema with Opis, the listener wiring). Red before the code: `~/memcap-work/build-all/learniq/lane6/red-foureyes.log`.
- [x] 1.2 Change `ReportPeriodLockGuard` to require an approved request for the entry and to keep the fraud check byte for byte. Verify: PHPUnit for the four cases in the spec; a wiring test from the GradeEntry schema.
  - `ReportPeriodLockGuardTest` (9, over the real `CorrectionApprovals`; the former `testMentorOverrideAllowsPublishOnLockedPeriod` is replaced by the principal-alone case), `CorrectionAppliedHandlerTest` (2, the real `ObjectTransitionedEvent`), `DataCorrectionRequestRegisterTest::testGradeEntryPublishRunsTheLockGuardAndLinksTheCorrection`.

## 2. UI

- [x] 2.1a Add the corrections page (/grades/corrections, index and detail, approve and reject as lifecycle actions) and an "Ask for a correction" action on the grade entry page. Verify: `npm run check:manifest`, `check:menu-role-gates`.
- [ ] 2.1b Playwright flow: request, approve as another user, republish. Needs the branch deployed on the shared instance.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n` (en and nl added; `check:schema-l10n` back at its baseline).
- [ ] 3.2 Set row `gov-four-eyes-on-approved-data` to built and archive the change. Verify: parity_verify --strict. Waits on 2.1b.


# Tasks: stop one person changing data that has already been approved

## 1. Register and guard

- [x] 1.1 Add `DataCorrectionRequest` with its lifecycle and a decision guard (approver differs from requester). Verify: `npm run check:register`, PHPUnit for approve, self-approve and reject.
  - `DataCorrectionDecisionGuardTest` (3), `DataCorrectionRequestStampTest` (3), `DataCorrectionRequestRegisterTest` (7: transitions and their groups, read rule, notification, the written payloads and the demo row against the shipped schema with Opis, the listener wiring). Red before the code: `~/memcap-work/build-all/learniq/lane6/red-foureyes.log`.
- [x] 1.2 Change `ReportPeriodLockGuard` to require an approved request for the entry and to keep the fraud check byte for byte. Verify: PHPUnit for the four cases in the spec; a wiring test from the GradeEntry schema.
  - `ReportPeriodLockGuardTest` (9, over the real `CorrectionApprovals`; the former `testMentorOverrideAllowsPublishOnLockedPeriod` is replaced by the principal-alone case), `CorrectionAppliedHandlerTest` (2, the real `ObjectTransitionedEvent`), `DataCorrectionRequestRegisterTest::testGradeEntryPublishRunsTheLockGuardAndLinksTheCorrection`.
- [x] 1.3 Live pass D2 (2 Oct): a stored report period has no `isLocked` (OpenRegister returns the materialised calculation on the create response only), so the lock guard failed open. `ReportPeriodLocks` decides locked from `lockDate` (a stored `true` counts too); the publish guard, the compose guard and the freeze below all ask it. Verify: `ReportPeriodLockStoredPeriodTest` (6: the period stored as the register stores it, validated with Opis), `ReportPeriodLocksTest`. Red before the code: `~/memcap-work/build-all/learniq/lanefix/d2-red.log`.
- [x] 1.4 Live pass D4 (2 Oct): a plain update changed a published grade in a locked period. `PublishedGradeFreezeListener` (ObjectUpdatingEvent) refuses a change to value, scale, weight, component, period or plan of a published entry in a locked period, for every signed-in user; revise + republish on an approved correction stays the way. Verify: `PublishedGradeFreezeWiringTest` (every updating listener the app registers, on the real event, over real classes). Red before the code: `~/memcap-work/build-all/learniq/lanefix/d4-red.log`.
- [x] 1.5 Live pass D10 (3 Oct): after D9 the handler ran and its save was refused, "Cannot modify readOnly properties: appliedAt, appliedBy"; its second write (the entry's readOnly `correctionRequestId`) would have been refused the same way. OpenRegister refuses an update that changes a readOnly property whoever saves; a declared transition action writes it on the save path after that check. So the `apply` transition stamps `appliedBy`/`appliedAt` (StampTransitionActorAction), the GradeEntry `republish` transition links the covering request (`LinkCoveringCorrectionAction`), and `CorrectionAppliedHandler` only moves the request to `applied`. Register 0.34.34. Verify: `CorrectionAppliedThroughApplyTransitionTest` (5, through `RegisterFaithfulStore`, which now refuses readOnly changes with OpenRegister's message and runs the declared transition's guard and actions). Red before the code: `~/memcap-work/build-all/learniq/lane17/d10-red.log`.

## 2. UI

- [x] 2.1a Add the corrections page (/grades/corrections, index and detail, approve and reject as lifecycle actions) and an "Ask for a correction" action on the grade entry page. Verify: `npm run check:manifest`, `check:menu-role-gates`.
- [ ] 2.1b Playwright flow: request, approve as another user, republish. Needs the branch deployed on the shared instance.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n` (en and nl added; `check:schema-l10n` back at its baseline).
- [ ] 3.2 Set row `gov-four-eyes-on-approved-data` to built and archive the change. Verify: parity_verify --strict. Waits on 2.1b.


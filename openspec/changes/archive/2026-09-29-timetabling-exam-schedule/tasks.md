# Tasks: schedule exams and test weeks with rooms and invigilators

## 1. Register and placement

- [x] 1.1 Add `ExamPeriod`, `ExamSitting` and `InvigilatorAvailability` to the register with lifecycles. Verify: `npm run check:register`, PHPUnit for the register shape.
  - Four schemas, not three: `InvigilatorAssignment` carries the request (design D3). `tests/Unit/Settings/ExamScheduleRegisterTest.php`, including the written payloads validated against the real fragments.
- [x] 1.2 Implement the placement service with capacity and clash checks. Verify: PHPUnit for too small, clash and clean placement against the real conflict detector.
  - The conflict detector is not usable at HEAD (design D1); `ExamSittingPlacementCheck` does the check. `tests/Unit/ExamSchedule/ExamSittingPlacementCheckTest.php`.

## 2. Accommodations

- [x] 2.1 Resolve approved accommodations per sitting (extra time, separate room). Verify: PHPUnit for approved, requested and revoked.
  - `ExamSittingOverview`; `tests/Unit/ExamSchedule/ExamSittingOverviewTest.php` (approved, active, requested, another exam's, a learner outside the class).

## 3. Invigilators

- [x] 3.1 Add availability, assignment, confirm and decline with notifications. Verify: PHPUnit for the four states; hydra gates 5, 7 and 30.
  - `InvigilatorAssignmentCheckTest`, `InvigilatorResponseGuardTest`, the controller half of `ExamSittingOverviewTest` (403 for a learner).

## 4. UI

- [x] 4.1 Add the exam schedule page, the placement dialog and the invigilator availability page. Verify: Playwright flow create a period, place a sitting, assign and confirm.
  - Built as manifest pages (`src/manifest.d/exam-schedule.json`: test weeks, sittings with their invigilation requests, requests, availability) using the standard create dialog; no custom dialog. `tests/e2e/exam-schedule.spec.ts` drives the flow through the API these pages use (too small refused, clash named, extra time, available invigilator asked, decline opens the place). It runs in CI; this lane had no browser against the branch.

## 5. Close out

- [x] 5.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
  - English and Dutch (the strict locales of `check:l10n`); the other locales fall back to English under the ratchet.
- [x] 5.2 Archive the change; list rows `tt-exam-schedule`, `tt-exam-accommodations`, `tt-invigilator-assignment` for the coordinator to set built in the planninq matrix. Verify: parity_verify --strict on learniq.
  - Archived in the same PR; the three planninq rows are listed in the lane's STATE.md for the coordinator. learniq's own matrix carries none of them.


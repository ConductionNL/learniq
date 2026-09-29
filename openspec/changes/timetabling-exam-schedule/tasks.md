# Tasks: schedule exams and test weeks with rooms and invigilators

## 1. Register and placement

- [ ] 1.1 Add `ExamPeriod`, `ExamSitting` and `InvigilatorAvailability` to the register with lifecycles. Verify: `npm run check:register`, PHPUnit for the register shape.
- [ ] 1.2 Implement the placement service with capacity and clash checks. Verify: PHPUnit for too small, clash and clean placement against the real conflict detector.

## 2. Accommodations

- [ ] 2.1 Resolve approved accommodations per sitting (extra time, separate room). Verify: PHPUnit for approved, requested and revoked.

## 3. Invigilators

- [ ] 3.1 Add availability, assignment, confirm and decline with notifications. Verify: PHPUnit for the four states; hydra gates 5, 7 and 30.

## 4. UI

- [ ] 4.1 Add the exam schedule page, the placement dialog and the invigilator availability page. Verify: Playwright flow create a period, place a sitting, assign and confirm.

## 5. Close out

- [ ] 5.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 5.2 Archive the change; list rows `tt-exam-schedule`, `tt-exam-accommodations`, `tt-invigilator-assignment` for the coordinator to set built in the planninq matrix. Verify: parity_verify --strict on learniq.


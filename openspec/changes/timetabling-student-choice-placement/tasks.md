# Tasks: place a student's elective choices in their timetable

## 1. Timetable

- [ ] 1.1 Add enrolment based session resolution to the timetable source and merge without duplicates. Verify: PHPUnit for elective, withdrawn and duplicate cases.

## 2. Picker

- [ ] 2.1 Show slots and the overlap warning in the picker. Verify: vitest for the overlap logic; Playwright flow choose two overlapping electives.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Archive the change; list row `tt-student-choice` for the coordinator. Verify: parity_verify --strict on learniq.


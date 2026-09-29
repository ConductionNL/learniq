# Tasks: make a part of a learning path mandatory for one person and optional for another

## 1. Register and service

- [ ] 1.1 Add `courseRequirements` to `Programme` and the default handling at programme enrolment. Verify: PHPUnit for default, override and a programme with no requirements; `npm run check:register`.
- [ ] 1.2 Compute progress from mandatory enrolments. Verify: PHPUnit for the optional-course case.

## 2. UI

- [ ] 2.1 Add the per-course switch to the programme form and the per-person switch to the enrolment form; list optional parts on the learner home widget. Verify: Playwright flow author marks optional, enrol, manager overrides.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `enr-path-mandatory-per-person` to built and archive the change. Verify: parity_verify --strict.


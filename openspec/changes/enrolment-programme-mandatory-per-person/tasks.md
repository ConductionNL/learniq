# Tasks: make a part of a learning path mandatory for one person and optional for another

## 1. Register and service

- [x] 1.1 Add `mandatoryCourseIds` to `Programme` (design D3; the proposal's `courseRequirements` map is not built) and the default handling at programme enrolment in both paths (D4). Verify: PHPUnit for default, override and a programme with no requirements; `npm run check:register`.
  - `ProgrammeRequirementsTest` (3), `CatalogueSignUpServiceTest::testAProgrammeSignUpTakesEachPartsDefault`, `ApplicationConversionHandlerTest::testPlacementEnrolsEachPartWithTheProgrammeDefault` (both validate the written enrolment against the shipped Enrolment schema with Opis), `ProgrammeMandatoryPartsRegisterTest` (2). Red before the code, green after: `~/memcap-work/build-all/learniq/lane5/red-programme.log`. `check:register` PASS.
- [x] 1.2 Compute progress from mandatory enrolments (D5). Verify: PHPUnit for the optional-course case.
  - `ProgrammeProgressTest` (4: optional part does not block completion, a per-person override, a programme without marks, only the caller's rows) and `ProgrammeProgressControllerTest` (2).

## 2. UI

- [x] 2.1a The programme form edits `mandatoryCourseIds` from the schema (title "Mandatory courses"); the enrolment form at /enrolments already edits `mandatory` per enrolment (D4); the learner home has a "My programmes" widget that lists optional parts under their own heading.
- [ ] 2.1b Playwright flow: author marks a course optional, enrol, manager overrides, learner home shows it. Needs the branch deployed on the shared instance.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run check:l10n` and `check:schema-l10n` (en and nl added; the other locales stay at the ratchet baseline).
- [ ] 3.2 Set row `enr-path-mandatory-per-person` to built and archive the change. Verify: parity_verify --strict. Waits on 2.1b.

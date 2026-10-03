# Tasks: make a part of a learning path mandatory for one person and optional for another

## 1. Register and service

- [x] 1.1 Add `mandatoryCourseIds` to `Programme` (design D3; the proposal's `courseRequirements` map is not built) and the default handling at programme enrolment in both paths (D4). Verify: PHPUnit for default, override and a programme with no requirements; `npm run check:register`.
  - `ProgrammeRequirementsTest` (3), `CatalogueSignUpServiceTest::testAProgrammeSignUpTakesEachPartsDefault`, `ApplicationConversionHandlerTest::testPlacementEnrolsEachPartWithTheProgrammeDefault` (both validate the written enrolment against the shipped Enrolment schema with Opis), `ProgrammeMandatoryPartsRegisterTest` (2). Red before the code, green after: `~/memcap-work/build-all/learniq/lane5/red-programme.log`. `check:register` PASS.
- [x] 1.2 Compute progress from mandatory enrolments (D5). Verify: PHPUnit for the optional-course case.
  - `ProgrammeProgressTest` (4: optional part does not block completion, a per-person override, a programme without marks, only the caller's rows) and `ProgrammeProgressControllerTest` (2).

## 2. UI

- [x] 2.1a The programme form edits `mandatoryCourseIds` from the schema (title "Mandatory courses"); the enrolment form at /enrolments already edits `mandatory` per enrolment (D4); the learner home has a "My programmes" widget that lists optional parts under their own heading.
- [x] 2.1b Live flow on the shared dev instance (2 Oct, learniq 1197793c, browser): the author marks one of two courses mandatory, the learner signs up for the programme (one mandatory, one optional enrolment), the manager switches the optional one to mandatory, and the learner home reads "0 of 2 mandatory courses done". Evidence: `~/memcap-work/build-all/livepass/learniq/enrolment-programme-mandatory-per-person/RESULT.md`.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run check:l10n` and `check:schema-l10n` (en and nl added; the other locales stay at the ratchet baseline).
- [x] 3.2 Set row `enr-path-mandatory-per-person` to built and archive the change. Verify: parity_verify --strict.

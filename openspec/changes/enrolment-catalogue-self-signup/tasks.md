# Tasks: enrolment-catalogue-self-signup

## Implementation tasks

### Task 1: Register: selfEnrolment, Enrolment request fields, notifications
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-course-or-programme-says-whether-learners-may-sign-up`, `#requirement-the-learner-is-told-in-words-that-fit-a-chosen-course`
- **files**: `lib/Settings/learniq_register.json` (Course 0.5.0, Programme 0.3.0, Enrolment 0.3.0, notifications; `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/CatalogueSignUpRegisterTest.php`; gate 18; `npm run check:register`

### Task 2: Catalogue and sign-up service with routes
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue`, `#requirement-a-learner-signs-up-for-a-whole-programme`, `#requirement-a-learner-withdraws-their-own-sign-up`
- **files**: `lib/Service/CatalogueSignUpService.php`, `lib/Controller/CatalogueController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a closed course WHEN a learner signs up THEN it is refused and no enrolment exists
  - GIVEN an enrolment with progress WHEN the learner withdraws THEN it is refused
- [ ] Implement
- [ ] Test: `tests/Unit/Service/CatalogueSignUpServiceTest.php`, `tests/Unit/Controller/CatalogueControllerTest.php`; hydra gates 5, 7, 30

### Task 3: Learner catalogue page and menu entry
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue`, `#requirement-provider-courses-show-their-provider`
- **files**: `src/views/CourseCatalogue.vue`, `src/manifest.d/my-learning.json`, `src/menu-layout.json` if needed, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/course-catalogue.spec.ts` (search, sign up, withdraw)

### Task 4: Sign-up requests view and course forms
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager`
- **files**: `src/manifest.d/people.json` (SignUpRequests), `src/manifest.d/learning.json` (Course and Programme forms, the imported filter)
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/course-catalogue.spec.ts` (manager approves a request)

### Task 5: Seed data and translations
- **files**: training example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate enrolment-catalogue-self-signup --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

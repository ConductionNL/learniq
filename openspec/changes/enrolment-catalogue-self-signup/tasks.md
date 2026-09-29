# Tasks: enrolment-catalogue-self-signup

## Implementation tasks

### Task 1: Register: selfEnrolment, Enrolment request fields, notifications
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-course-or-programme-says-whether-learners-may-sign-up`, `#requirement-the-learner-is-told-in-words-that-fit-a-chosen-course`
- **files**: `lib/Settings/learniq_register.json` (Course 0.5.0, Programme 0.3.0, Enrolment 0.3.0, notifications; `info.version` bump)
- [x] Implement
- [x] Test: `tests/Unit/Settings/CatalogueSignUpRegisterTest.php`; gate 18; `npm run check:register`
- OpenRegister has no field filter on a `transition` trigger, so the chosen-course message is a `created` trigger filtered on `source: self` and `lifecycle: active`: an open sign-up is created active (no `activate`, so the mandatory text never fires). Requests get `approve` and `decline` transitions with their own messages instead of the mandatory `activate`. A manager may update a pending self sign-up of their own report (the one write widened; `DeclaredAudienceEnforcedTest` names it).

### Task 2: Catalogue and sign-up service with routes
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue`, `#requirement-a-learner-signs-up-for-a-whole-programme`, `#requirement-a-learner-withdraws-their-own-sign-up`
- **files**: `lib/Service/CatalogueSignUpService.php`, `lib/Controller/CatalogueController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a closed course WHEN a learner signs up THEN it is refused and no enrolment exists
  - GIVEN an enrolment with progress WHEN the learner withdraws THEN it is refused
- [x] Implement
- [x] Test: `tests/Unit/Service/CatalogueSignUpServiceTest.php`, `tests/Unit/Controller/CatalogueControllerTest.php`; hydra gates 5, 7, 30
- Service split into `CatalogueReader` (list) and `CatalogueSignUpService` (writes, as the learner via `runAs`, `_rbac: false` after the checks). Portal receivers `PortalCatalogueController` (list, sign up, withdraw) on the pattern of #1096 and #1142, with actions in `CatalogueFlowActions`. Controller test is `tests/Unit/Controller/PortalCatalogueControllerTest.php` (covers the app controller too).

### Task 3: Learner catalogue page and menu entry
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue`, `#requirement-provider-courses-show-their-provider`
- **files**: `src/views/CourseCatalogue.vue`, `src/manifest.d/my-learning.json`, `src/menu-layout.json` if needed, `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/course-catalogue.spec.ts` (search, sign up, withdraw)
- Live 2026-09-29 on localhost:8080 (#1460, served 71a2c414): the coordinator and learner tests passed (56.8s, 3.0m). Admin sets "Sign-up by learners" to Open on the course form (selfEnrolment becomes open). A temporary learner with a learner profile then searches, signs up (one enrolment: active, source self) and withdraws (withdrawn, "Sign up" shows again). They sign up for a programme of three courses (three enrolments, each with the programmeId), see "Provider: Go1" on a provider course, and request a place on an on-request course (one enrolment: pending, self).
- Page `src/views/CourseCatalogue.vue` at `/catalogue`, menu under My learning. Playwright test not written: no live instance in this lane.

### Task 4: Sign-up requests view and course forms
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager`
- **files**: `src/manifest.d/people.json` (SignUpRequests), `src/manifest.d/learning.json` (Course and Programme forms, the imported filter)
- [x] Implement
- [ ] Test: Playwright `tests/e2e/course-catalogue.spec.ts` (manager approves a request)
- Live 2026-09-29 (#1460): a temporary team lead (not the manager) approves the pending request from EnrolmentDetail, and the enrolment becomes active. The test stays red on its soft check for a button named "Approve": nextcloud-vue 2.57.1 `CnLifecycleActions.labelFor()` labels the transition with its schema description. Open until the label reads Approve and the manager path runs.
- Sign-up requests are a menu preset on the Enrolments index (`query: {source: self, lifecycle: pending}`), and "Imported, not yet published" a preset on the Courses index (`lifecycle: draft`, `license: all-rights-reserved`), per ADR-097 decision 5 (no second index page per schema); approve and decline come from EnrolmentDetail's lifecycle actions. The lti-lesson part of the imported filter is left out (an index filter cannot join lessons). Playwright test not written.

### Task 5: Seed data and translations
- **files**: training example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate enrolment-catalogue-self-signup --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
- Mock register rows carry `selfEnrolment`, `author: Go1` and three self enrolments (active, pending, withdrawn); the training example set generator is not extended.


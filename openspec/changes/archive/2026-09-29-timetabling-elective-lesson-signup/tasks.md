# Tasks: timetabling-elective-lesson-signup

## Implementation tasks

### Task 1: Register: ElectiveOffer, ElectiveSignUp, integration scope
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity`
- **files**: `lib/Settings/learniq_register.json` (two schemas with lifecycle, aggregation and authorization; the `elective-integrations` scope; `info.version` bump)
- [x] Implement
- [x] Test: `tests/Unit/Settings/ElectiveSignUpRegisterTest.php`; the register ratchet tests (`tests/Unit/Register`) pass

### Task 2: Rules listener
- **spec_ref**: `specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it`
- **files**: `lib/Listener/ElectiveSignUpRules.php`, `lib/Service/ElectiveService.php`, `lib/AppInfo/Registrar/ElectiveListenerRegistrar.php` (chained from `OnboardingListenerRegistrar`)
- **acceptance_criteria**:
  - GIVEN a full lesson WHEN a coordinator places a learner THEN it is refused
  - GIVEN a closed window WHEN a coordinator places a learner THEN it is written with `status: placed`
- [x] Implement
- [x] Test: `tests/Unit/Listener/ElectiveSignUpRulesTest.php` with the real `ObjectCreatingEvent` and `ObjectUpdatingEvent` classes; `RegisteredListenersHandleRealEventsTest` passes

### Task 3: Learner endpoint
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window`
- **files**: `lib/Controller/ElectiveController.php`, `lib/Service/ElectiveBoard.php`, `appinfo/routes.php`, `lib/actions.seed.json` (`elective.manage`)
- [x] Implement
- [x] Test: `tests/Unit/Controller/ElectiveControllerTest.php` (learnerId from the session, never the body), `tests/Unit/Service/ElectiveBoardTest.php`; hydra gates 5, 7, 30

### Task 4: Learner page and coordinator pages
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window`, `#requirement-a-coordinator-places-learners-who-missed-the-window`
- **files**: `src/views/MyElectives.vue`, `src/views/ElectiveRosterView.vue`, `src/manifest.d/my-learning.json`, `src/manifest.d/learning.json`, `src/registry.js`
- [x] Implement
- [x] Test: Playwright `tests/e2e/spec-coverage/timetabling-elective-lesson-signup.spec.ts`; written, not run: no instance in this lane

### Task 5: API write by another system
- **spec_ref**: `specs/enrolment/spec.md#requirement-another-system-signs-learners-up-through-the-api`
- **files**: none beyond tasks 1 and 2
- [x] Test: `ElectiveSignUpRulesTest::testAnIntegrationSignsUpUnderTheSameRules` runs the object-API write path (the creating event as a member of `elective-integrations`): stamped `madeVia: integration`, refused on a full lesson. A live HTTP test needs an instance, which this lane does not have.

### Task 6: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-elective-lesson-signup --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

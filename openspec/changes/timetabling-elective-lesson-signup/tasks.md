# Tasks: timetabling-elective-lesson-signup

## Implementation tasks

### Task 1: Register: ElectiveOffer, ElectiveSignUp, integration scope
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity`
- **files**: `lib/Settings/learniq_register.json` (two schemas with lifecycle, aggregation and authorization; the `elective-integrations` scope; `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/ElectiveSignUpRegisterTest.php`; the register ratchet tests (DeclaredAudienceEnforced, GuardGroupsAreDeclared) stay at their current set

### Task 2: Rules listener
- **spec_ref**: `specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it`
- **files**: `lib/Listener/ElectiveSignUpRules.php`, its registrar
- **acceptance_criteria**:
  - GIVEN a full lesson WHEN a coordinator places a learner THEN it is refused
  - GIVEN a closed window WHEN a coordinator places a learner THEN it is written with `status: placed`
- [ ] Implement
- [ ] Test: `tests/Unit/Listener/ElectiveSignUpRulesTest.php` with the real `ObjectCreatingEvent` class

### Task 3: Learner endpoint
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window`
- **files**: `lib/Controller/ElectiveController.php`, `appinfo/routes.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/ElectiveControllerTest.php` (learnerId from the session, never the body); hydra gates 5, 7, 30

### Task 4: Learner page and coordinator pages
- **spec_ref**: `specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window`, `#requirement-a-coordinator-places-learners-who-missed-the-window`
- **files**: `src/views/MyElectives.vue`, `src/manifest.d/my-learning.json`, `src/manifest.d/learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/electives.spec.ts` (sign up, withdraw, place after the window)

### Task 5: API write by another system
- **spec_ref**: `specs/enrolment/spec.md#requirement-another-system-signs-learners-up-through-the-api`
- **files**: none beyond tasks 1 and 2
- [ ] Test: an integration test that posts to OpenRegister's objects route for `elective-sign-up` as a member of `elective-integrations` and is refused on a full lesson

### Task 6: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-elective-lesson-signup --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

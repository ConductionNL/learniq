# Tasks: content-lti-launch-through-integriq

> **Round 5 (2026-09-28): built, except the live run.** Integriq's `LtiLaunchRequestedEvent` exists on integriq
> development (4ad84c67); a verbatim copy is the test stub. The subscription field lives in a new LTI section of
> learniq's admin settings (`src/views/settings/LtiSettingsSection.vue`), which the `lti` row links to: integriq's
> registry page shows what the owning app reports, so the row is `reportedOnly` and learniq reports it from whether
> the launch event exists (`ConnectionReportService::observeLti()`), like the timetable row. There is no "Create
> subscription" button: integriq offers no subscription event to raise, so the section says where to create it.
> Playwright for the row state is not added: the state is integriq's page; the unit tests pin learniq's side.

## Implementation tasks

### Task 1: Controller raises integriq's launch event
- **spec_ref**: `specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event`
- **files**: `lib/Controller/LtiToolPlacementController.php`
- **acceptance_criteria**:
  - GIVEN no integriq WHEN launch is called THEN 503 with a plain message
  - GIVEN a refused launch WHEN launch is called THEN 409 with the refusal's reason
- [x] Implement
- [x] Test: `tests/Unit/Controller/LtiToolPlacementControllerTest.php` with a stand-in event class; hydra gates 5, 7, 30

### Task 2: Lesson player submits the login form
- **spec_ref**: `specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event`
- **files**: `src/views/LessonPlayer.vue`
- [x] Implement
- [x] Test: `tests/unit-js` for the form builder (every field, method, target by launch mode): `tests/unit-js/ltiLaunchForm.test.mjs`

### Task 3: Grades resolve to the exact placement
- **spec_ref**: `specs/course-management/spec.md#requirement-a-returned-grade-lands-on-the-placement-that-launched-it`
- **files**: `lib/BackgroundJob/LtiAgsScorePollJob.php`
- [x] Implement
- [x] Test: `tests/Unit/BackgroundJob/LtiAgsScorePollJobTest.php` (two placements on one deployment; mismatched deployment falls back): `testLineItemPicksThePlacement`, red on development

### Task 4: Subscription setting and availability
- **spec_ref**: `specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works`
- **files**: `lib/Settings/connections.json`, the connection registry reader, the admin page
- [x] Implement
- [x] Test: unit test on the availability computation (done: `ConnectionReportServiceTest::testTheLtiRowFollowsIntegriqsLaunchEvent`, `ConnectionsDeclarationTest`); Playwright for the row state not added, see the note above
  - r5-live, 2026-09-29, shared dev instance: the row state now has a Playwright check. `tests/e2e/connection-registry.spec.ts` "the LTI row reads what learniq reported" asserts the `lti` row status equals learniq's last report (`configured` with integriq installed) and links /settings/admin/learniq#section-lti. 1 passed (#1427).

### Task 5: Live launch against a reference tool
- **files**: none (verification)
- [ ] (not run: no instance with integriq and a reference tool in this lane) Test: with integriq's change installed, open a lesson with the IMS reference tool and record in the PR body that the tool opened and a score came back as a concept grade
  - r5-live, 2026-09-29, shared dev instance, still open: no IMS reference tool is registered on the shared instance, so no tool can open and no score can come back.

## Verification
- `openspec validate content-lti-launch-through-integriq --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

# Tasks: content-lti-launch-through-integriq

## Implementation tasks

### Task 1: Controller raises integriq's launch event
- **spec_ref**: `specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event`
- **files**: `lib/Controller/LtiToolPlacementController.php`
- **acceptance_criteria**:
  - GIVEN no integriq WHEN launch is called THEN 503 with a plain message
  - GIVEN a refused launch WHEN launch is called THEN 409 with the refusal's reason
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/LtiToolPlacementControllerTest.php` with a stand-in event class; hydra gates 5, 7, 30

### Task 2: Lesson player submits the login form
- **spec_ref**: `specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event`
- **files**: `src/views/LessonPlayer.vue`
- [ ] Implement
- [ ] Test: `tests/unit-js` for the form builder (every field, method, target by launch mode)

### Task 3: Grades resolve to the exact placement
- **spec_ref**: `specs/course-management/spec.md#requirement-a-returned-grade-lands-on-the-placement-that-launched-it`
- **files**: `lib/BackgroundJob/LtiAgsScorePollJob.php`
- [ ] Implement
- [ ] Test: `tests/Unit/BackgroundJob/LtiAgsScorePollJobTest.php` (two placements on one deployment; mismatched deployment falls back)

### Task 4: Subscription setting and availability
- **spec_ref**: `specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works`
- **files**: `lib/Settings/connections.json`, the connection registry reader, the admin page
- [ ] Implement
- [ ] Test: unit test on the availability computation; Playwright `tests/e2e/connections.spec.ts` shows the LTI row state

### Task 5: Live launch against a reference tool
- **files**: none (verification)
- [ ] Test: with integriq's change installed, open a lesson with the IMS reference tool and record in the PR body that the tool opened and a score came back as a concept grade

## Verification
- `openspec validate content-lti-launch-through-integriq --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

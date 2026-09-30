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
  - lq-lti, 2026-09-29: the job read the score at `payload` level, but integriq stores the whole CloudEvent as the message payload, so the fields sit under `payload.data` and every score was skipped. Fixed; the tests now build messages through the ObjectEntity serialisation the way integriq does (`integriqMessage()`), and `testReadsTheScoreFromTheCloudEventDataOfARealMessage` is red on development.

### Task 4: Subscription setting and availability
- **spec_ref**: `specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works`
- **files**: `lib/Settings/connections.json`, the connection registry reader, the admin page
- [x] Implement
- [x] Test: unit test on the availability computation (done: `ConnectionReportServiceTest::testTheLtiRowFollowsIntegriqsLaunchEvent`, `ConnectionsDeclarationTest`); Playwright for the row state not added, see the note above
  - r5-live, 2026-09-29, shared dev instance: the row state now has a Playwright check. `tests/e2e/connection-registry.spec.ts` "the LTI row reads what learniq reported" asserts the `lti` row status equals learniq's last report (`configured` with integriq installed) and links /settings/admin/learniq#section-lti. 1 passed (#1427).

### Task 5: Live launch against a reference tool
- **files**: none (verification)
- [x] Test: with integriq's change installed, open a lesson with the IMS reference tool and record in the PR body that the tool opened and a score came back as a concept grade
  - r5-live, 2026-09-29, shared dev instance, still open: no IMS reference tool is registered on the shared instance, so no tool can open and no score can come back.
  - lq-lti, 2026-09-30, shared dev instance :8080 at development 7e3ecfe6 (integriq with #2380, #2382, #2386, #2391; learniq with #1492, #1508, #1518, #1534). Passed end to end, run once.
    - **Tool:** not the IMS reference implementation. It is a conformant LTI 1.3 tool built on packbackbooks/lti-1p3-tool 6.4.4, the maintained fork of 1EdTech's PHP library, served locally with `php -S`. The library validates the launch fully and sends its own AGS calls; no request was hand-built.
    - **Tool opened:** a learner in the `learners` group only launched a lesson-level placement (`courseId` null). The flow was learniq launch 200, then the tool's OIDC login, then integriq's authorize with an auto-post, then the tool page ("Tool opened, Signed in as lq-lti-e2e-09300934").
    - **id_token:** the library validated state, nonce, the RS256 signature against integriq's JWKS, the deployment and the message. Claims: `resource_link.id` = the placement; roles Learner; `context` = the lesson's course, with its name; `launch_presentation.return_url` = the lesson; and the AGS endpoint claim, with scopes lineitem.readonly and score and `lineitem` = integriq's line item route for the placement.
    - **Score came back:** the tool got a token without `deployment_id` and posted 8/10 to `lineitem/scores`: HTTP 200 `{"messagesCreated":1}`. Integriq queued an event message for the pull subscription, with `lineItemId` = the placement and `userId` = the learner.
    - **Concept grade:** `LtiAgsScorePollJob::run()` pulled the message, and the cursor advanced to it. It wrote one GradeEntry: `learnerId` lq-lti-e2e-09300934, `ltiToolPlacementId` = the placement, `curriculumPlanId` and `componentId` = the placement's, `sourceKind` lti-ags, `ltiAgsResultId` = the message id, `value` 8.2 (8/10 normalised onto the placement's 1–10 numeric scale), `grader` lti-ags, `lifecycle` concept.
    - **Setup that belongs to this run, not to the code (all reverted afterwards):**
      - `allow_local_remote_servers` was set for this run only. Learniq's pull calls `http://localhost` because `overwrite.cli.url` is localhost.
      - A dedicated pull service user was granted `event.pull`, and learniq's `lti_ags_subscription_id` and `openconnector_api_*` keys were set.
      - The poll job was run directly, because this instance's job list does not hold `LtiAgsScorePollJob`. That is an instance registration gap, not a code bug: Nextcloud registers the declared job on install and on every app upgrade.

## Verification
- `openspec validate content-lti-launch-through-integriq --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline

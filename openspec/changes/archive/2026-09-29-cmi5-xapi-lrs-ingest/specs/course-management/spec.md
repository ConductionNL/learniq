# Course Management: cmi5/xAPI LRS ingest delta

**Spec refs**: `course-management`, ADR-002 (content runtime: cmi5 + xAPI primary, SCORM shim)

## MODIFIED Requirements

### Requirement: Run cmi5 + xAPI natively with SCORM shim

The system MUST run cmi5 + xAPI content natively via a real LRS ingest endpoint
(`POST /api/lrs/statements`, `GET /api/lrs/statements`) that authenticates the caller (a signed cmi5 launch
JWT minted by `Cmi5LaunchTokenService`, or a Nextcloud session that passes the CSRF check), stamps
`verified_actor_id` server-side from the authenticated identity, NEVER from the posted statement's `actor.*`
fields, and persists the statement as an `XapiStatement` OpenRegister object. The `xapi-statement` schema MUST
NOT let a learner create statements through the generic object API; learners read only their own statements.
Launching a cmi5 AU MUST mint a signed RS256 launch token once an administrator has provisioned the key-pair,
hand the token to the AU only through a single-use fetch URL (never in the launch URL), and the launch endpoint
MUST return HTTP 503 with a human-readable body while `Cmi5LaunchTokenService::isEnabled()` is false. A
statement whose actor cannot be authenticated MUST NOT be stored. The SCORM 1.2 shim posts its statements to the
same endpoint; the SCORM 2004 shim and a cmi5 package importer remain follow-ups.

#### Scenario: Run cmi5/xAPI content with SCORM fallback
- **GIVEN** a lesson backed by a content package
- **WHEN** a learner launches the lesson
- **THEN** the system runs cmi5 + xAPI content natively
- **AND** it runs SCORM 1.2 packages through the compatibility shim, which posts to the same LRS endpoint (SCORM 2004 is a follow-up)

<!-- @e2e exclude Carried over from the canonical requirement; the launch paths are covered by tests/e2e/spec-coverage/progress-tracking.spec.ts and tests/Unit/Controller/Cmi5LaunchControllerTest.php. -->

#### Scenario: A SCORM 1.2 package's completion status produces a recognised xAPI statement

<!-- @e2e exclude The SCORM 1.2 API shim's completion-to-xAPI mapping is covered by tests/unit-js/scorm12Runtime.test.mjs; the POST target by LessonPlayer.vue postXapiStatement and tests/Unit/Controller/LrsControllerTest.php. -->

- **GIVEN** a `Lesson` with `contentType: "scorm12"` and a learner has launched it
- **WHEN** the package calls `LMSSetValue('cmi.core.lesson_status', 'completed')` (or `'passed'`)
- **THEN** an xAPI statement is built with `verb.id` equal to `http://adlnet.gov/expapi/verbs/completed` or
  `.../passed`, the same IRIs `XapiCompletionHandler` already recognises
- **AND** the statement is posted to `POST /api/lrs/statements`, which stamps `verified_actor_id` from the session

#### Scenario: A cmi5 lesson gracefully degrades until the sibling ingest change ships

- **GIVEN** a `Lesson` with `contentType: "cmi5"` and no cmi5 launch signing key is provisioned
  (`Cmi5LaunchTokenService::isEnabled()` is false, so the launch endpoint answers 503)
- **WHEN** a learner opens the lesson
- **THEN** `LessonPlayer.vue` shows a clear "cmi5 playback is not yet available for this lesson" empty state
- **AND** no unhandled error or infinite loading spinner is shown

<!-- @e2e exclude Depends on instance key state, which a shared instance cannot toggle per test; the 503 half is pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php, the empty state is the `!cmi5.available` branch of src/views/LessonPlayer.vue. -->

#### Scenario: A learner's completed AU produces a queryable xAPI statement

- **GIVEN** a Lesson with `contentType: cmi5` and a learner with a valid, unexpired launch JWT
- **WHEN** the AU posts a `completed` statement to `POST /api/lrs/statements`
- **THEN** the statement is persisted as an `XapiStatement` with `verified_actor_id` set to the
  authenticated learner's identity
- **AND** the statement is queryable via `GET /api/lrs/statements`, scoped to that learner for a non-admin caller

<!-- @e2e exclude Server-to-server AU call with a launch token, no UI; pinned by tests/Unit/Controller/LrsControllerTest.php. -->

#### Scenario: A forged actor claim in the statement body is ignored

- **GIVEN** a learner is authenticated with their own valid launch JWT
- **WHEN** they post a statement whose `actor.account.name` claims a different learner's identifier
- **THEN** the persisted `XapiStatement.verified_actor_id` is the authenticated caller's own identity
- **AND** it is NOT the identifier claimed in `actor.account.name`

<!-- @e2e exclude Trust boundary of the ingest endpoint, no UI; pinned by tests/Unit/Controller/LrsControllerTest.php. -->

#### Scenario: Launch is unavailable before the signing key is provisioned

- **GIVEN** `Cmi5LaunchTokenService::isEnabled()` returns false (no RS256 key-pair provisioned yet)
- **WHEN** a learner attempts to launch a `cmi5` Lesson
- **THEN** the launch endpoint returns HTTP 503 with a human-readable error body
- **AND** no launch token is issued

<!-- @e2e exclude Depends on instance key state; pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php, and the lesson player empty state by tests/e2e/spec-coverage/progress-tracking.spec.ts. -->

#### Scenario: The launch token never travels in the launch URL

- **GIVEN** a provisioned key and a learner who may read the lesson
- **WHEN** the learner launches the cmi5 lesson
- **THEN** the answer carries `endpoint`, `fetchUrl`, `actor`, `activityId` and `registration`, and no token
- **AND** the fetch URL hands out the token once, and a second call is refused

<!-- @e2e exclude Token hand-off, no UI; pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php. -->

#### Scenario: SCORM 2004 is not claimed done

- **GIVEN** a Lesson with `contentType: scorm2004`
- **WHEN** the wedge plan is read
- **THEN** `openspec/WEDGE-PLAN.md` names the SCORM 2004 shim as a follow-up rather than a blanket "built" status

<!-- @e2e exclude Documentation state, no UI; checked by reading openspec/WEDGE-PLAN.md. -->

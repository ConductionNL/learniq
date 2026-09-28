# assessment Specification

## ADDED Requirements

### Requirement: Portal test requests are accepted only from portaliq's signed forward

`PortalAssessmentController` MUST verify the `X-Portal-Subject` assertion before any read, with
`PortalAssertionVerifier`: HS256 with portaliq's dedicated `jwt_signing_secret` (at least 16
characters), `use: assertion`, `iss: portaliq`, `exp` in the future, `iat` not in the future and a
non-empty `sub`. A request without a valid assertion MUST get 401 and register a failed attempt for
brute-force throttling. The claim `audience` MUST be `student`, else 403. The learner MUST be taken
only from the body's `learnerRef`, which portaliq stamps from the subject's own account; a request
without it MUST get 403. A `learnerRef` that names no active LearnerProfile, or a profile whose
Nextcloud account does not exist, MUST get 403 `not_available`. No Nextcloud session MUST be used
as a fallback. Every write MUST run as the pupil's own account through `ObjectService::runAs()`.

#### Scenario: A request without a valid assertion is refused

<!-- @e2e exclude Server-to-server receiver with no DOM surface; covered by PHPUnit PortalAssessmentControllerTest and PortalAssertionVerifierTest. -->

- **GIVEN** a request to any of the five endpoints
- **WHEN** the assertion is missing, forged, expired or a session token
- **THEN** the response is 401 `unauthorized` and nothing is read

#### Scenario: Another audience or a missing learner is refused

<!-- @e2e exclude PHPUnit PortalAssessmentControllerTest. -->

- **GIVEN** a valid assertion for audience `parent`, or a body without `learnerRef`
- **WHEN** it reaches an endpoint
- **THEN** the response is 403 `forbidden`

### Requirement: A portal attempt follows every test rule inside the endpoints

The portal endpoints MUST enforce, themselves, every rule a portal attempt is subject to, because
the attempt gate and the integrity listener exempt a caller without a Nextcloud user. `available`
MUST list only tests that are `published`, have no `proctoring` configuration, belong to a course or
cohort the pupil holds an active or pending Enrolment in, pass `LessonReleaseEvaluator` (window,
drip, release conditions) and have attempts left, plus any attempt in progress. `start` MUST refuse
with 403 `not_available` whatever `available` would not list, MUST refuse a missing or wrong access
code with 403 `access_code_required` or `access_code_wrong`, MUST resume an attempt in progress
instead of creating a second one, and MUST allow a new attempt only while the pupil's attempts are
fewer than `maxAttempts` (default 1). The deadline MUST be `startedAt + timeLimitMinutes × (1 +
p / 100)`, where `p` is the value of an approved or active `extra-time-percentage`
`ExamAccommodation` for the pupil, one for this test before a generic one. `answer` MUST save one
question at a time, only on the pupil's own attempt in progress, only for an item the attempt drew,
only in the shape the item's type takes, and MUST refuse with 409 `attempt_closed` after hand-in or
after the deadline plus 30 seconds. The first request that finds an attempt past that point MUST hand
it in. `submit` MUST fire the attempt's `submit` transition, so closed items are auto-scored, and
MUST refuse a second hand-in with 409.

#### Scenario: A test outside its window cannot be started

<!-- @e2e exclude PHPUnit PortalAttemptServiceTest::testATestOutsideItsWindowIsNotAvailable. -->

- **GIVEN** a published test whose `availableFrom` is tomorrow
- **WHEN** an enrolled pupil starts it through the portal
- **THEN** the response is 403 `not_available` and no attempt is created

#### Scenario: The access code is required

<!-- @e2e exclude PHPUnit PortalAttemptServiceTest (missing, wrong and right code). -->

- **GIVEN** a test with an access code
- **WHEN** the pupil starts it without the code, or with a wrong one
- **THEN** the response is 403 `access_code_required` or `access_code_wrong`
- **AND** with the right code an attempt is created as the pupil, carrying the typed code for the attempt gate

#### Scenario: One attempt unless retakes are allowed

<!-- @e2e exclude PHPUnit PortalAttemptServiceTest::testASecondAttemptIsRefusedWhenOnlyOneIsAllowed and testARetakeIsAllowedUpToMaxAttempts. -->

- **GIVEN** a test with `maxAttempts: 1` and a handed-in attempt by this pupil
- **WHEN** the pupil starts it again
- **THEN** the response is 403 `not_available`

#### Scenario: Extra time moves the deadline

<!-- @e2e exclude PHPUnit PortalAttemptClockTest and PortalAttemptServiceTest::testExtraTimeMovesTheDeadline. -->

- **GIVEN** a 30 minute test and an active 25% extra-time accommodation for the pupil
- **WHEN** the pupil starts at 09:00
- **THEN** `deadlineAt` is 09:37:30 and the task shows 7.5 minutes of extra time

#### Scenario: Answers are saved per question and never after hand-in

<!-- @e2e exclude PHPUnit PortalAttemptServiceTest (save, unknown item, invalid response, after submit, after deadline). -->

- **GIVEN** an attempt in progress
- **WHEN** the pupil saves an answer to one drawn item
- **THEN** only that item's response changes, stored as `{value: …}` with no score
- **AND** after hand-in, or after the deadline plus 30 seconds, the save is refused with 409 `attempt_closed`

#### Scenario: Handing in scores the closed items

<!-- @e2e exclude PHPUnit PortalAttemptServiceTest::testSubmitFiresTheTransitionAsThePupil and AssessmentScoringHandlerTest::testTheStoredValueShapeIsScored. -->

- **GIVEN** an attempt in progress with a correct multiple-choice answer
- **WHEN** the pupil hands it in
- **THEN** the `submit` transition runs as the pupil and the answer's `autoScore` is the item's points

### Requirement: A portal result is shown only once the teacher released it

`result` MUST answer `{released: false}` until the attempt is `graded` and, when it fed a
GradeEntry, that entry is `published` or `revised` with no `visibleFrom` in the future. Once
released it MUST return the total score, the maximum from the drawn points, `passed` (only for a
pass-mark test) and per item the prompt, the pupil's answer, the score and the maximum, and never a
correct answer.

#### Scenario: A graded attempt waits for the published grade

<!-- @e2e exclude PHPUnit PortalResultReaderTest. -->

- **GIVEN** a graded attempt whose GradeEntry is still `concept`
- **WHEN** the pupil asks for the result
- **THEN** the response is `{released: false}`
- **AND** once the GradeEntry is published, the score and the per-item scores are returned

### Requirement: Auto scoring reads the stored answer shape

`AssessmentScoringHandler` MUST score a response stored as `{value: X}` (the shape
`TakeAssessmentView` and the portal store, and `ItemAnalysisService` and `AssessmentScoringView`
read) by comparing `X` with the item's correct response. A bare value MUST keep scoring as before.

#### Scenario: A wrapped correct answer earns its points

<!-- @e2e exclude PHPUnit AssessmentScoringHandlerTest::testTheStoredValueShapeIsScored. -->

- **GIVEN** a choice item worth 2 points with correct response `B`
- **WHEN** an attempt with `response: {value: "B"}` is handed in
- **THEN** its `autoScore` is 2

### Requirement: Every attempt carries a server-stamped learnerRef and assessment title

The attempt gate MUST stamp `AssessmentResult.learnerRef` (the LearnerProfile of `learnerId`,
preferring one not merged away) and `assessmentTitle` (the test's title) on every create it lets
through, overwriting client values, so the portal can list a pupil's attempts. A failed lookup MUST
stamp null and MUST NOT stop the create. Both properties MUST be `readOnly`.

#### Scenario: An attempt made in the app shows up in the portal

<!-- @e2e exclude PHPUnit AssessmentResultPortalStampTest and AssessmentAttemptGateListenerTest. -->

- **GIVEN** a pupil with LearnerProfile `lp-1`
- **WHEN** the pupil starts a test in the app, sending `learnerRef: "lp-2"`
- **THEN** the stored attempt carries `learnerRef: "lp-1"` and the test's title

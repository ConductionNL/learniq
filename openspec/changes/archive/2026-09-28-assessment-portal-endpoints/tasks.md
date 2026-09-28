# Tasks: assessment-portal-endpoints

## Implementation Tasks

### Task 1: Add PortalAssertionVerifier
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward`
- **files**: `lib/Portal/PortalAssertionVerifier.php`, `tests/Unit/Portal/PortalAssertionVerifierTest.php`
- **acceptance_criteria**:
  - A token minted like portaliq's `PortalJwtService::createAssertion()` verifies to its claims
  - Forged, expired, future-issued, session, wrong-issuer, `none`-algorithm and short-secret tokens return null
- [x] Implement
- [x] Test

### Task 2: Stamp learnerRef and the title on every attempt
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title`
- **files**: `lib/Service/AssessmentResultPortalStamp.php`, `lib/Service/Portal/LearnerProfileLookup.php`, `lib/Listener/AssessmentAttemptGateListener.php`, `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/*`, tests
- **acceptance_criteria**:
  - AssessmentResult declares readOnly `learnerRef` and `assessmentTitle`; versions bumped; seeds carry both
  - The gate stamps both for every create it lets through, never for a refused one
- [x] Implement
- [x] Test

### Task 3: Auto scoring reads the stored answer shape
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-auto-scoring-reads-the-stored-answer-shape`
- **files**: `lib/Lifecycle/AssessmentScoringHandler.php`, `tests/Unit/Lifecycle/AssessmentScoringHandlerTest.php`
- **acceptance_criteria**:
  - A `{value: "B"}` answer to a `B` item earns its points (red before the fix)
- [x] Implement
- [x] Test

### Task 4: Resolve the pupil and the clock
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints`
- **files**: `lib/Service/Portal/PortalLearner.php`, `PortalLearnerResolver.php`, `PortalAttemptClock.php`, tests
- **acceptance_criteria**:
  - An unknown profile or a missing account resolves to null
  - Deadline includes extra time, test-specific before generic, clamped; untimed is null
- [x] Implement
- [x] Test

### Task 5: Present items without answers
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints`
- **files**: `lib/Service/Portal/PortalItemPresenter.php`, `lib/Service/Portal/PortalAnswerShape.php`, `tests/Unit/Service/Portal/PortalItemPresenterTest.php`, `tests/Unit/Service/Portal/PortalAnswerShapeTest.php`
- **acceptance_criteria**:
  - QTI 2.x and 3.0 items become `{itemId, type, prompt, points, choices?, sources?, targets?}` in the drawn option order
  - No correct response appears; answers are checked against the item's type and options
- [x] Implement
- [x] Test

### Task 6: Catalogue and attempt service
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints`
- **files**: `lib/Service/Portal/PortalAttemptReader.php`, `PortalAttemptWriter.php`, `PortalAttemptCloser.php`, `PortalAttemptPayload.php`, `PortalAnswerRules.php`, `PortalAssessmentCatalogue.php`, `PortalAttemptService.php`, `PortalOutcome.php`, `tests/Stubs/Service/ObjectService.php`, `tests/Support/PortalFakeRegister.php`, tests
- **acceptance_criteria**:
  - A test per rule: published, proctored, enrolment, tenant, window, attempts, code, resume, extra time, per-question save, item and shape checks, after hand-in, after the deadline, submit as the pupil
- [x] Implement
- [x] Test

### Task 7: The release rule
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it`
- **files**: `lib/Service/Portal/PortalResultReader.php`, `tests/Unit/Service/Portal/PortalResultReaderTest.php`
- **acceptance_criteria**:
  - Not released until graded and the GradeEntry is published and visible; then scores per item without correct answers
- [x] Implement
- [x] Test

### Task 8: Controller, routes and the portal contribution
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008`
- **files**: `lib/Controller/PortalAssessmentController.php`, `lib/Service/Portal/PortalMessages.php`, `appinfo/routes.php`, `lib/Portal/PortalContributionProvider.php`, `l10n/*`, tests
- **acceptance_criteria**:
  - 401 with throttling, 403 for audience, learner and profile; each route delegates; 502 hides internals
  - `studentTests` is a timed task naming five local POST actions with `subjectField: learnerRef`
- [x] Implement
- [x] Test

### Task 9: Document tests in the portal
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008`
- **files**: `docs/user-guide/user/06-grading.md`
- **acceptance_criteria**:
  - Teachers read what the portal offers, which tests stay in the app, and when a pupil sees a result
- [x] Implement

## Quality checklist

- Every new class has a PHPUnit test class; every rule has a test
- New pupil-facing messages have English and Dutch catalogue entries
- `openspec validate assessment-portal-endpoints` passes

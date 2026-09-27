# Test Plan: assessment-portal-endpoints

## Test Cases

### TC-1: The receiver refuses anything but a valid student assertion
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward`
- **type**: security
- **steps**: forged, expired, session-token, wrong-issuer, `none`-algorithm, short-secret assertions; audience `parent`; no `learnerRef`; unknown profile; profile without an account
- **expected result**: verifier returns null; controller 401 (throttled) / 403 `forbidden` / 403 `not_available`
- **test command**: `vendor/bin/phpunit --filter 'PortalAssertionVerifierTest|PortalAssessmentControllerTest|PortalLearnerResolverTest'`

### TC-2: What a pupil may start
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints`
- **type**: functional
- **steps**: tests that are draft, proctored, of another school, outside the window, of a course the pupil is not in, with attempts used; one in progress
- **expected result**: only the open ones and the one in progress are listed; `start` answers 403 `not_available` for the rest
- **test command**: `vendor/bin/phpunit --filter 'PortalAssessmentCatalogueTest|PortalAttemptServiceTest'`

### TC-3: Access code, attempts, resume
- **spec_ref**: same requirement
- **type**: security
- **steps**: start without / with a wrong / with the right code; start again after hand-in with `maxAttempts` 1 and 2; start with an attempt in progress
- **expected result**: 403 codes; a created attempt as the pupil carrying the typed code; 403 then allowed; the same attempt resumed
- **test command**: `vendor/bin/phpunit --filter PortalAttemptServiceTest`

### TC-4: Deadline, extra time, auto hand-in
- **spec_ref**: same requirement
- **type**: functional
- **steps**: 30 minutes with 25% extra time (generic and test-specific); an answer 10 s and 31 s past the deadline
- **expected result**: deadline 37:30; the first answer saved, the second refused with 409 and the attempt handed in
- **test command**: `vendor/bin/phpunit --filter 'PortalAttemptClockTest|PortalAttemptServiceTest'`

### TC-5: Answers per question, shapes, nothing after hand-in
- **spec_ref**: same requirement
- **type**: functional
- **steps**: save choice, text, order and match answers; an unknown item; an option not in the item; another pupil's attempt; a handed-in attempt
- **expected result**: one response replaced as `{value}` with null scores; 422 `unknown_item`, 422 `invalid_response`, 404, 409
- **test command**: `vendor/bin/phpunit --filter 'PortalAttemptServiceTest|PortalItemPresenterTest'`

### TC-6: Hand-in scores closed items
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-auto-scoring-reads-the-stored-answer-shape`
- **type**: regression
- **steps**: submit fires the transition as the pupil; score a `{value: "B"}` answer against `B`
- **expected result**: transition called inside `runAs`; `autoScore` equals the points
- **test command**: `vendor/bin/phpunit --filter 'PortalAttemptServiceTest|AssessmentScoringHandlerTest'`

### TC-7: The release rule
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it`
- **type**: security
- **steps**: submitted; graded with a concept GradeEntry; published with a future `visibleFrom`; published; graded without GradeEntry
- **expected result**: not released, not released, not released, released with scores, released
- **test command**: `vendor/bin/phpunit --filter PortalResultReaderTest`

### TC-8: Items leave without answers
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints`
- **type**: security
- **steps**: present QTI 2.x and 3.0 choice, inline choice, order, match and essay items with a `correctResponse`
- **expected result**: prompts and options in the drawn order; no correct value anywhere in the payload
- **test command**: `vendor/bin/phpunit --filter PortalItemPresenterTest`

### TC-9: The stamp and the manifest
- **spec_ref**: `openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title`, `openspec/changes/assessment-portal-endpoints/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008`
- **type**: api
- **expected result**: forged `learnerRef` replaced, title stamped, refused creates not stamped; `studentTests` names five local POST actions with `subjectField`
- **test command**: `vendor/bin/phpunit --filter 'AssessmentResultPortalStampTest|AssessmentAttemptGateListenerTest|PortalContributionProviderTest'`

## Coverage Summary
Every requirement in both spec deltas is covered by TC-1 to TC-9.

## Out of Scope
- A live run through portaliq: #749 is not merged and lanes do not touch the shared instance.

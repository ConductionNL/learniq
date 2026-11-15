# assignments Specification

## ADDED Requirements

### Requirement: A pupil hands in a portal draft through learniq's own endpoint

`POST /api/portal/submissions/hand-in` (`PortalSubmissionController::handIn()`) MUST accept only
portaliq's signed forward, in the order of `PortalAssessmentController`: a missing or invalid
`X-Portal-Subject` assertion MUST get 401 and register a failed attempt for throttling; an audience
other than `student`, or a body without `learnerRef`, MUST get 403; a `learnerRef` without an active
LearnerProfile and Nextcloud account MUST get 403 `not_available`. The submission named by the body's
`submissionId` MUST carry the pupil's `learnerRef` or list the pupil's Nextcloud id in `learnerIds`;
otherwise, or when it does not exist, the answer MUST be one 404 `not_found`. A submission whose
`lifecycle` is not `draft` MUST get 409 `already_handed_in`. The endpoint MUST ask
`SubmissionWindowGuard` which hand-in applies for the pupil: `submit` when the guard allows it, else
`submitLate` when the guard allows that; when the guard allows neither, the answer MUST be 422
`late_not_accepted` when the deadline passed on an assignment that takes no late work, else 422
`hand_in_refused`, each with the pupil-facing message, and nothing MUST be written. The chosen
transition MUST run through OpenRegister's `TransitionEngine` as the pupil (`ObjectService::runAs()`),
so the guard runs again on the write; a refusal there MUST be answered the same way. Success MUST be
200 `{submissionId, lifecycle}` with `submitted` or `late`.

#### Scenario: A draft inside the window is handed in

<!-- @e2e exclude Server-to-server receiver with no DOM surface in learniq; the button is portaliq's. Covered by PHPUnit PortalSubmissionHandInTest::testADraftInsideTheWindowIsSubmittedAsThePupil. -->

- **GIVEN** a pupil's draft submission on an assignment due tomorrow
- **WHEN** portaliq forwards `handIn` for it
- **THEN** `submit` runs as the pupil and the answer is 200 with `lifecycle: submitted`

#### Scenario: After the deadline the late rule decides

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testAfterTheDeadlineLateWorkIsHandedInLate and ::testAfterTheDeadlineWithoutLateWorkNothingIsWritten. -->

- **GIVEN** a pupil's draft on an assignment whose deadline passed
- **WHEN** the assignment accepts late work, and when it does not
- **THEN** the first runs `submitLate` and answers `lifecycle: late`; the second answers 422 `late_not_accepted` with the message and fires no transition

#### Scenario: Another pupil's submission is not reachable

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testAnotherPupilsSubmissionIs404. -->

- **GIVEN** a submission whose `learnerRef` and `learnerIds` name another pupil
- **WHEN** a pupil forwards `handIn` with its id
- **THEN** the answer is 404 `not_found`, the same as for an id that does not exist, and no transition runs

#### Scenario: A handed-in submission is not handed in twice

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testASubmissionThatIsNotADraftIs409. -->

- **GIVEN** a pupil's submission that is already `submitted`
- **WHEN** portaliq forwards `handIn` for it
- **THEN** the answer is 409 `already_handed_in` and no transition runs

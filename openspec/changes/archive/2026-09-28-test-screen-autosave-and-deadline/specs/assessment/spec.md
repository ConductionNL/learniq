# Assessment: in-app screen deadline and autosave delta

## ADDED Requirements

### Requirement: The in-app test screen shows the server's deadline and saves answers as the learner works

When a learner starts an attempt with a time limit, the server MUST stamp `deadlineAt` on the AssessmentResult: its start plus the time limit plus the learner's granted extra time, the moment the late-answer rule measures against without the grace. The learner MUST NOT change it. The test screen MUST count down to `deadlineAt`, corrected for a wrong browser clock, also when the attempt is resumed, and MUST save the learner's answers on the attempt shortly after each change while it is in progress, telling the learner whether they are saved.

#### Scenario: The timer counts down to the server's deadline
@e2e exclude Timer and stamp are pinned by unit tests: tests/Unit/Service/AssessmentAttemptLimitsTest.php::testTheDeadlineCountsExtraTime, tests/Unit/Listener/AssessmentAttemptTimeLimitListenerTest.php::testTheLearnerCannotMoveTheDeadline and tests/unit-js/attemptClock.test.mjs.
- **GIVEN** a 30-minute test and a learner with 50 percent extra time
- **WHEN** the learner starts at 09:10
- **THEN** the attempt's `deadlineAt` is 09:55
- **AND** the screen shows 45:00 remaining
- **AND** a save that moves `deadlineAt` is refused

#### Scenario: Answers are saved while the learner works
@e2e exclude Autosave payload and resume are pinned by tests/unit-js/attemptClock.test.mjs; the save runs through the rules pinned in AssessmentResultIntegrityListenerTest.
- **GIVEN** an attempt in progress
- **WHEN** the learner answers a question and pauses
- **THEN** the answers are saved on the attempt, without scores
- **AND** reopening the attempt shows them

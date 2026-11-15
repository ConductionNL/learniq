# Assessment: in-app attempt limits on the server delta

## ADDED Requirements

### Requirement: The in-app test screen enforces attempts and time on the server

An attempt a learner starts or saves through the app, not the portal, MUST be held by the server to the same rules as a portal attempt, with the same parts: a new attempt MUST start only inside the window, with the access code, and while the learner has used fewer attempts than the test's `maxAttempts` (default one); the server MUST set the attempt's `startedAt` from its own clock and its `attemptNumber` from the attempts already made; the learner MUST NOT change either afterwards; and after the deadline (start plus time limit plus the learner's extra time) plus 30 seconds, the attempt's answers MUST NOT change, while the save that carries them, a hand-in included, goes through with the answers stored in time. Scores the server adds to unchanged answers MUST be kept. Nextcloud admins and system context are not held to these rules.

#### Scenario: A second attempt on a one-attempt test is refused

- **GIVEN** a test with `maxAttempts: 1` and a learner who has handed in one attempt
- **WHEN** the learner starts another attempt from the test screen
- **THEN** the server refuses it with reason `attempts-used`
- **AND** the screen says the attempts are used

#### Scenario: The server starts the clock

- **GIVEN** a test with `maxAttempts: 3` and one earlier attempt by the learner
- **WHEN** the screen creates an attempt with `startedAt` in the future and `attemptNumber: 1`
- **THEN** the attempt is stored with the server's time as `startedAt` and `attemptNumber: 2`
- **AND** a later save by the learner that moves `startedAt` or `attemptNumber` is refused

#### Scenario: Answers after the deadline are not saved

- **GIVEN** a 30-minute test and an attempt started at 09:00
- **WHEN** the learner saves changed answers at 09:30:31
- **THEN** the save goes through with the answers that were stored
- **AND** at 09:30:20, or at 09:44 with 50 percent extra time, the changed answers are saved

#### Scenario: A late hand-in keeps the answers given in time

- **GIVEN** the same attempt, still in progress at 09:40
- **WHEN** the learner hands it in with changed answers
- **THEN** the attempt is handed in with the stored answers
- **AND** the submit save that adds auto scores to those answers keeps the scores

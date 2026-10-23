## ADDED Requirements

### Requirement: Staff reach the proctoring flag review page

Learniq MUST offer a page at `/assessments/proctoring/review` that lists every `ProctoringSession` with at least one flag whose `reviewDecision` is `pending`. The page MUST be reachable from the Assessments menu and from the Proctoring sessions list for users in `instructors` or `compliance-officers`, and the menu entry MUST NOT show for anyone else.

#### Scenario: A teacher opens the queue

- **GIVEN** a native test-mode session with two pending flags and a user in `instructors`
- **WHEN** the user opens Assessments and chooses "Proctoring review"
- **THEN** the page lists that session with "2 open flags"

#### Scenario: A learner does not see the entry

- **GIVEN** a user in no staff group
- **WHEN** the user opens the navigation
- **THEN** no proctoring review entry is shown

### Requirement: Each session card says whose test it was and what happened

A session card MUST show the assessment title, the learner's display name, the provider and the number of open flags. Each flag MUST show its kind in words, the date and time it happened and, when the attempt has a start time, the whole minutes since the start. A decided flag MUST show the decision, the reviewer's name and the date instead of the buttons.

#### Scenario: A flag reads as a sentence

- **GIVEN** a `tab-hidden` flag at 10.14 on an attempt that started at 10.02
- **WHEN** the reviewer opens the queue
- **THEN** the flag reads "Switched tab or minimised the window" with "12 minutes in"

#### Scenario: A decided flag shows who decided

- **GIVEN** a flag Pieter Jansen allowed on 6 October at 15.20
- **WHEN** another reviewer opens the session
- **THEN** the flag reads "Allowed by Pieter Jansen on 6 October" and offers no buttons

### Requirement: Only staff decide a flag, and the server records who did

A write to `proctoring-session` by a user outside `instructors` and `compliance-officers` MUST be refused when it changes or removes a stored flag, or adds a flag whose `reviewDecision` is not `pending`. A staff write MAY move a flag's `reviewDecision` from `pending` to `allowed` or `annulled` once; the server MUST set `reviewedBy` to the caller and `reviewedAt` to the server time, ignoring any value in the body. A write with no user or by an administrator MUST NOT be checked.

#### Scenario: A learner tries to allow their own flag

- **GIVEN** a learner with an active session holding a pending `fullscreen-exit` flag
- **WHEN** the learner PATCHes the session with that flag's `reviewDecision` set to `allowed`
- **THEN** the write is refused and the flag stays `pending`

#### Scenario: Native test mode still appends a flag

- **GIVEN** a learner with an active session
- **WHEN** the browser appends a new `window-blur` flag with `reviewDecision: "pending"`
- **THEN** the write is accepted

#### Scenario: A reviewer annuls a flag

- **GIVEN** a user in `compliance-officers` and a pending flag
- **WHEN** the user chooses Annul
- **THEN** the flag is `annulled`, `reviewedBy` is that user and `reviewedAt` is the server time
- **AND** the linked `AssessmentResult` is unchanged

#### Scenario: A decision is not changed afterwards

- **GIVEN** a flag already `allowed`
- **WHEN** a staff user writes `annulled` on it
- **THEN** the write is refused

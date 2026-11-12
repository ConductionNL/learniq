## ADDED Requirements

### Requirement: An applicant submits an application to an open admissions round

A person who wants to join MUST be able to fill in an application for an `AdmissionsRound` in state `open` and submit it themselves. The `submit` transition MUST stamp `submittedAt` and MUST be refused when the round is not open.

#### Scenario: An applicant applies before the deadline

- **GIVEN** an admissions round for "Verzorgende IG" in state `open`
- **WHEN** an applicant fills in their name, date of birth and prior school and submits
- **THEN** an application exists for that round in state `submitted` with `submittedAt` set

#### Scenario: The round has closed

- **GIVEN** an admissions round in state `closed`
- **WHEN** an applicant tries to submit an application to it
- **THEN** the submission is refused with a message that the round is closed

### Requirement: A review panel decides an application together

An admissions round MUST be able to name a review panel. Each panel member MUST be able to record their own advice on an application. The decision to place, waitlist or reject MUST be recorded only once the panel has reached it, and it MUST keep each member's advice with the decision.

#### Scenario: Three panel members review one application

- **GIVEN** a round with a panel of three members and an application in `intake-completed`
- **WHEN** each member records their advice and the panel agrees to place the applicant
- **THEN** the application moves to `placed`
- **AND** the decision shows the advice of all three members

#### Scenario: One member cannot decide alone

- **GIVEN** the same application with advice from one member only
- **WHEN** that member tries to place the applicant
- **THEN** the decision is refused until the panel has decided

### Requirement: A learner withdraws from an optional lesson and sees when sign-up opens

On the optional lessons page, a learner signed up for a lesson MUST be offered "Withdraw" while the window is open, and withdrawing MUST free their place. A lesson whose window has not opened yet MUST show the date sign-up opens and MUST offer no button.

#### Scenario: A learner withdraws inside the window

- **GIVEN** learner j.bakker signed up for a Thursday lesson with 12 free places, its window open
- **WHEN** j.bakker chooses "Withdraw" on that lesson
- **THEN** the sign-up is withdrawn and the lesson shows 13 free places and "Sign up"

#### Scenario: The window has not opened yet

- **GIVEN** a lesson whose sign-up opens in three days
- **WHEN** j.bakker opens "Optional lessons"
- **THEN** the lesson shows "Sign-up opens" with that date and no sign-up button

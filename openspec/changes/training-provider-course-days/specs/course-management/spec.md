## ADDED Requirements

### Requirement: A course day has a minimum, a decision date and a status that moves by itself

A course day (`cohort` of a training course) MUST carry a minimum and a maximum number of participants, the date by which the provider decides whether it goes ahead, and a status derived from its bookings, enrolments, decision and date (open, goes ahead, full, rounding off, done, cancelled), recomputed on every change. A day that reaches its minimum MUST move to goes ahead without anyone dragging it. A planner MUST be able to decide go or no-go, and on no-go move its participants to another day of the same course with free places.

#### Scenario: Four of six
- **GIVEN** Hybride warmtepomp on 22 October has 4 participants, minimum 6, decide by Thursday 8 October
- **WHEN** Sophie opens Today on Monday 5 October
- **THEN** the card reads "Hybride warmtepomp op 22 oktober heeft 4 deelnemers. Het minimum is 6." and names the next day of that course with places
- @e2e exclude staff screen; spec-only proposal

#### Scenario: The sixth booking
- **GIVEN** the same day at 5 participants
- **WHEN** a sixth participant is booked
- **THEN** the day's status is goes ahead
- @e2e exclude derivation asserted in unit tests

### Requirement: A course says what to bring

A course MAY list what a participant brings; a participant's next course day and the public course page MUST show that list.

#### Scenario: F-gassen
- **GIVEN** F-gassen: herhaling en examen lists a valid ID, the current certificate and work shoes
- **WHEN** Tom opens his next course day on his phone
- **THEN** he reads the three lines under "Meenemen"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

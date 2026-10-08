## ADDED Requirements

### Requirement: A conference round says whether pupils may book

A conference round MUST carry `pupilMayBook`, default false. Only when it is true MAY a learner book a time of that round for herself; a guardian's booking for the same learner and round MUST then be refused as already booked, and the other way round. The round's invitation notice to a pupil MUST link to the booking page and MUST NOT carry the times themselves.

#### Scenario: Pupils may not book a parent evening
- **GIVEN** a round for groep 7 parents with `pupilMayBook: false`
- **WHEN** a pupil account tries to book a time
- **THEN** the booking is refused and no time changes
- @e2e exclude refusal asserted in the guard's unit tests

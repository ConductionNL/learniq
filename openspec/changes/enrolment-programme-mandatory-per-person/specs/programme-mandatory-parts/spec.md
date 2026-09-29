## ADDED Requirements

### Requirement: Mandatory and optional parts of a programme

The system MUST let a programme author mark each course of a programme as mandatory or optional. Enrolling a person in the programme MUST create course enrolments with `mandatory` set from that default. A manager MUST be able to change the flag for one person without changing the programme or another person.

#### Scenario: An author marks a part optional

- **GIVEN** an author editing the programme Safety basics with three courses
- **WHEN** the author marks Site tour optional and saves
- **THEN** new enrolments to the programme create Site tour with mandatory false and the other two with mandatory true

#### Scenario: A manager makes a part mandatory for one person

- **GIVEN** a person enrolled with Site tour optional
- **WHEN** the manager sets Site tour to mandatory for that person in /enrolments
- **THEN** only that person's enrolment changes and other people keep optional

### Requirement: Progress counts mandatory parts

Programme progress and completion MUST be computed from mandatory enrolments only, and the learner home widget MUST list optional parts under their own heading without counting them toward completion.

#### Scenario: Optional parts do not block completion

- **GIVEN** a person who completed both mandatory courses and none of the optional one
- **WHEN** the programme progress is read
- **THEN** it shows 100 percent complete and lists the optional course as optional

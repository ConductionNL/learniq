# programme-mandatory-parts Specification

## Purpose
TBD - created by archiving change enrolment-programme-mandatory-per-person. Update Purpose after archive.

## Requirements

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

Programme progress and completion MUST be computed from mandatory enrolments only. Mijn leren MUST read the progress as "{done} van {total} verplichte cursussen klaar", or "verplichte vakken" for a pupil, and MUST list optional parts under "Keuzecursussen" or "Keuzevakken" without counting them toward completion.

#### Scenario: Optional parts do not count

- **GIVEN** a person who finished three of the five mandatory courses of "Basiskwalificatie didactische bekwaamheid" and did not start the optional one
- **WHEN** the person opens Mijn leren
- **THEN** the programme reads "3 van 5 verplichte cursussen klaar" and lists "Formatief evalueren in de les" under "Keuzecursussen" as "Niet gestart"

#### Scenario: Optional parts do not block completion

- **GIVEN** a person who finished all five mandatory courses and did not start the optional one
- **WHEN** the programme progress is read
- **THEN** it reads "5 van 5 verplichte cursussen klaar", the programme is complete, and the optional course stays under "Keuzecursussen"

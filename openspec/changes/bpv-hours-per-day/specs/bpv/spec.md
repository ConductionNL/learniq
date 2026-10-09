## ADDED Requirements

### Requirement: A week of BPV hours holds its days

A `bpv-hour-week` MUST hold day lines with date, start and end time, break, hours, description, work process codes and an optional photo, and its submitted hours MUST be the sum of its day lines. A day line's date MUST fall inside the week, and its hours MUST be the time between start and end minus the break.

#### Scenario: Monday 08.00 to 16.30 with 30 minutes break
- **GIVEN** Milan's week 41
- **WHEN** he writes Monday 5 October from 08.00 to 16.30 with 30 minutes break
- **THEN** the day reads 8 hours and the week's submitted hours include them
- @e2e tests/e2e/portal-design/esdoornveen.spec.ts

### Requirement: A returned week names the day and the question

When a praktijkopleider returns a week, she MUST name one of its days and write a question; the week MUST record both on that day line, and the student MUST receive a notice from her with the question and a link to change that day.

#### Scenario: The dentist on Tuesday
- **GIVEN** Milan wrote 8 hours on Tuesday 29 September
- **WHEN** Petra returns week 40 naming Tuesday with "Volgens mij ging je om 14.00 uur naar de tandarts"
- **THEN** Milan's messages show "Vraag over je uren van dinsdag 29 september" with "Uren aanpassen", and the day reads "Teruggestuurd"
- @e2e tests/e2e/portal-design/esdoornveen.spec.ts

#### Scenario: A return without a question
- **GIVEN** a submitted week
- **WHEN** the trainer returns it with no day or no question
- **THEN** the return is refused in words
- @e2e exclude refusal asserted in the guard's unit tests

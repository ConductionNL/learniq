## ADDED Requirements

### Requirement: An exam sitting shows each accommodated learner's end time

`ExamSittingDetail` MUST show a section that reads `GET /api/exam-sittings/{id}/overview` and lists every learner in the sitting's classes with an applying exam accommodation: the learner's name, the extra time in percent, the end time with that extra time, and whether the learner sits in a separate room. When no learner has an accommodation the section MUST say so.

#### Scenario: A learner with 25 percent extra time

- **GIVEN** a sitting from 09:00 to 11:00 for class 4H and a learner in 4H with an approved accommodation of 25 percent extra time
- **WHEN** a planner opens the sitting
- **THEN** the section lists that learner with "25%" and an end time of 11:30

#### Scenario: A revoked accommodation

- **GIVEN** the same learner whose accommodation was revoked
- **WHEN** the planner opens the sitting
- **THEN** the learner is not listed

#### Scenario: Nobody has extra time

- **GIVEN** a sitting whose classes hold no learner with an applying accommodation
- **WHEN** the planner opens it
- **THEN** the section says "No learner in these classes has extra time"

### Requirement: An exam sitting shows its invigilator places and who is free

The section MUST show the sitting's invigilator places (needed, confirmed, asked, open) from the overview route, and the colleagues from `GET /api/exam-sittings/{id}/available-invigilators`, each with an "Ask" action. "Ask" MUST create a pending `invigilator-assignment` for the sitting through the OpenRegister objects API, after which the section MUST reload so the colleague moves from free to asked.

#### Scenario: Ask a free colleague

- **GIVEN** a sitting that needs 3 invigilators, 1 confirmed and 0 asked, and a colleague free for the whole sitting
- **WHEN** the planner chooses "Ask" next to that colleague
- **THEN** a pending invigilation request for that colleague and sitting exists
- **AND** the section shows "1 of 3 confirmed, 1 asked, 1 open" and no longer lists the colleague as free

#### Scenario: Nobody is free

- **GIVEN** a sitting for which no colleague's availability covers the whole time
- **WHEN** the planner opens it
- **THEN** the section says "Nobody else is free for the whole sitting"

#### Scenario: A sitting the caller may not read

- **GIVEN** a user without read access to the sitting
- **WHEN** the section requests the overview
- **THEN** the route answers 404 and the section shows nothing of the sitting

# exam-schedule Specification

## Purpose
Let a planner place exams in a test week with room, clash, accommodation and invigilator checks. Placement is by hand; the schedule is never generated. Built by `timetabling-exam-schedule` (archived 2026-09-29).

## Requirements

### Requirement: Exam periods and sittings

The system MUST let a planner create an exam period and place an assessment in it as a sitting with a start, an end and one or more rooms. Placement MUST be refused or warned when the rooms' capacity is below the headcount or when the room or the cohort is already booked in that slot, against the lessons and other exams in those rooms and classes.

#### Scenario: A planner places an exam in a test week

- **GIVEN** a planner on the exam schedule page for the period Toetsweek 2
- **WHEN** the planner places Maths B in room A1 on Tuesday 09:00 to 10:30
- **THEN** the sitting is stored and shown in the period overview

#### Scenario: A room too small is flagged

- **GIVEN** an exam for 60 learners
- **WHEN** the planner picks a room with capacity 30
- **THEN** the placement is refused with the capacity and the headcount shown

#### Scenario: A clash is flagged

- **GIVEN** a cohort with another lesson at that time
- **WHEN** the planner places an exam for that cohort
- **THEN** an exam-clash warning names the other session

### Requirement: Accommodations in the schedule

The system MUST show, on a sitting, each learner with an approved exam accommodation and MUST apply extra time to that learner's end time and MUST create a separate room slot when the accommodation requires one.

#### Scenario: Extra time lengthens the end

- **GIVEN** a learner with an approved accommodation of 25 percent extra time and a 60 minute exam
- **WHEN** the sitting is read for that learner
- **THEN** the end is 75 minutes after the start and the learner is listed on the sitting

#### Scenario: An unapproved accommodation is ignored

- **GIVEN** an accommodation still in requested state
- **WHEN** the sitting is read
- **THEN** no extra time is applied

### Requirement: Invigilator assignment

The system MUST let an invigilator state availability for an exam period, MUST let a planner assign available invigilators to sittings, and MUST require the invigilator to confirm or decline. An unconfirmed assignment MUST be shown as pending, and a declined one MUST reopen the slot.

#### Scenario: An invigilator is assigned and confirms

- **GIVEN** an invigilator available on Tuesday morning and a sitting at 09:00
- **WHEN** the planner assigns the invigilator and the invigilator confirms
- **THEN** the sitting shows the invigilator as confirmed

#### Scenario: A decline reopens the slot

- **GIVEN** a pending assignment
- **WHEN** the invigilator declines
- **THEN** the sitting shows an open invigilator slot

#### Scenario: An unavailable invigilator is not offered

- **GIVEN** an invigilator who did not state availability on Tuesday
- **WHEN** the planner assigns invigilators for a Tuesday sitting
- **THEN** the invigilator is not in the list of available people

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

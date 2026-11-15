## ADDED Requirements

### Requirement: Exam periods and sittings

The system MUST let a planner create an exam period and place an assessment in it as a sitting with a start, an end and one or more rooms. Placement MUST be refused or warned when the rooms' capacity is below the headcount or when the room or the cohort is already booked in that slot, using the existing conflict rules.

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

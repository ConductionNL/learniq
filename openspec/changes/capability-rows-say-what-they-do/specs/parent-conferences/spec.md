## ADDED Requirements

### Requirement: A teacher enters and submits their own free times for a conference round

A teacher MUST be able to create a `TeacherAvailability` for a conference round with their user id as `teacherId` and one or more time blocks, and submit it. Submitting MUST move it from `draft` to `submitted`. Only a submitted or locked availability MUST be used when free slots are made or a schedule is planned.

#### Scenario: A teacher submits her free evening

- **GIVEN** a conference round "Oudergesprekken november" in `draft` or `invitations-sent`
- **WHEN** a teacher adds an availability for that round from 18:00 to 20:00 on Thursday and chooses "Submit"
- **THEN** the availability is stored with her as teacher, her display name and that block, and reads `submitted`

#### Scenario: A draft availability is not used

- **GIVEN** a teacher's availability that is still `draft`
- **WHEN** booking opens for the round
- **THEN** no free slot is made from it

### Requirement: A guardian chooses which teachers they want to speak to in a signup

The booking page MUST let a guardian pick the round, the child and the teachers they want to speak to, in order of preference, from the round's `teacherIds`. The signup MUST store that order in `requestedTeacherIds`, and the scheduling generator MUST walk it in that order. A signup without a chosen teacher MUST NOT be submitted from that page.

#### Scenario: A guardian asks for the mentor and the maths teacher

- **GIVEN** a round offering five teachers and a guardian linked to the child
- **WHEN** the guardian picks the round, the child, then the mentor and the maths teacher in that order, and submits
- **THEN** the signup is `submitted` with `requestedTeacherIds` holding the mentor first and the maths teacher second

#### Scenario: A teacher outside the round cannot be asked for

- **GIVEN** a round whose `teacherIds` do not include a certain teacher
- **WHEN** the guardian opens the teacher list
- **THEN** that teacher is not offered

## ADDED Requirements

### Requirement: Online meeting link on a lesson

The system MUST let a teacher of the lesson set an https meeting link on a session, and MUST show a Join action on the learner's timetable row and the lesson page when a link exists. A link MUST only be shown to people who may see the session. A link that is not https MUST be refused.

#### Scenario: A learner joins from the timetable

- **GIVEN** a session with an online meeting link
- **WHEN** a learner opens My timetable and chooses Join on that lesson
- **THEN** the meeting opens in a new tab

#### Scenario: An unsafe link is refused

- **GIVEN** a teacher editing a session
- **WHEN** the teacher enters a link that starts with javascript:
- **THEN** the save is refused with the reason

#### Scenario: A lesson without a link shows none

- **GIVEN** a session without a link
- **WHEN** a learner opens My timetable
- **THEN** no Join action is shown

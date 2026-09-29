## ADDED Requirements

### Requirement: Calendar subscription feed

The system MUST let a signed-in user create a personal timetable feed address and MUST serve that user's sessions at that address as iCalendar without a session, protected by an unguessable token. A cancelled lesson MUST appear with the cancelled status, and a substitute teacher and a changed room MUST appear in the event. The feed MUST contain only what the user's timetable page shows, within the school's timetable visibility policy.

#### Scenario: A learner subscribes in their calendar

- **GIVEN** a learner on My timetable
- **WHEN** the learner chooses Subscribe in your calendar, copies the address and adds it to a calendar app
- **THEN** the calendar shows the learner's lessons for the coming twelve weeks with rooms

#### Scenario: A cancelled lesson is shown as cancelled

- **GIVEN** a lesson cancelled this morning
- **WHEN** the calendar app refreshes the feed
- **THEN** the event has the cancelled status

#### Scenario: A feed respects visibility

- **GIVEN** a school policy that hides teacher names from learners
- **WHEN** a learner's feed is fetched
- **THEN** no teacher name is in the events

### Requirement: Revoking the feed address

The system MUST let the user reset the feed address, and the old address MUST answer 404 immediately.

#### Scenario: A leaked address is reset

- **GIVEN** a user whose feed address was shared
- **WHEN** the user chooses Reset link
- **THEN** the old address answers 404 and a new address is shown

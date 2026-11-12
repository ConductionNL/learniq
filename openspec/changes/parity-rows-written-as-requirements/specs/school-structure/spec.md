## ADDED Requirements

### Requirement: A lesson is scheduled with a time, a room and a teacher

A `Session` MUST carry its group, course, start and end time and a room. The group's teachers teach it, and a substitute teacher MAY be set through the `substitute-teacher` transition. A scheduled lesson MUST appear in the timetables of its group, its teachers and its room.

#### Scenario: A planner schedules a lesson

- **GIVEN** the group 3HV-B with teacher j.devries and the room A1.12
- **WHEN** a planner schedules a lesson for 3HV-B on Tuesday 10:15 to 11:05 in A1.12
- **THEN** the lesson is `scheduled` and shows in the timetables of 3HV-B, j.devries and A1.12

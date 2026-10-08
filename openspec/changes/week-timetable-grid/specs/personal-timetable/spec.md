## ADDED Requirements

### Requirement: A teacher reads her week as day columns and lesson hours

learniq MUST offer a week page with the working days as columns and the school's lesson hours as rows, placing each session the user teaches or covers in its hour with subject, group and room, marking today, and marking a changed session (room, cancelled, substitute) with a pill in words. A mentor MUST be able to switch to her mentor class's week. A session MUST open its own page.

#### Scenario: Week 41 for Sanne Kramer
- **GIVEN** Sanne has 20 lessons and 1 meeting from Monday 5 to Friday 9 October
- **WHEN** she opens Weekrooster
- **THEN** the heading reads "Week 41, maandag 5 tot en met vrijdag 9 oktober, 20 lessen en 1 overleg" and Monday is marked today
- @e2e exclude staff screen; spec-only proposal

#### Scenario: A change from the roosterkamer
- **GIVEN** the roosterkamer moves economie of H4b to room 0.21
- **WHEN** the week is opened again
- **THEN** that lesson shows room 0.21 with the pill "Ander lokaal"
- @e2e exclude as above

### Requirement: A training planner sees this week's course days with their occupancy

For a training provider, Today MUST show this week's five days with each course day's course, trainer, room and occupancy "{booked} van {capacity}" with a bar, and a day without a course day MUST read so in words.

#### Scenario: A free day
- **GIVEN** no course day on Monday 5 October
- **WHEN** Sophie opens Today
- **THEN** Monday reads "Geen cursusdag. De hal is vrij."
- @e2e exclude staff screen; spec-only proposal

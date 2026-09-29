# school-structure Specification

## ADDED Requirements

### Requirement: A planner sees how well rooms are used

For a chosen period, learniq MUST report per room the hours in use (the duration of lessons in that room that were not cancelled, within opening hours), the hours the building is open (opening hours per weekday on teaching days, holidays left out), the occupancy rate, and the average fill of the room (group size against capacity), with a weekday by hour grid of the share of rooms in use. The report MUST be filterable by room kind and building, reachable from the Reports page, exportable as CSV, and readable by `instructors`, `team-leads` and `compliance-officers` only.

#### Scenario: A deputy head checks the gyms

- **GIVEN** two gyms used 32 and 33 of 35 open hours in a week, and three labs used 12 hours each
- **WHEN** the deputy head opens Reports, "Room use", picks that week and filters on gyms and labs
- **THEN** the gyms show above 90 percent occupancy and the labs about 34 percent
- **AND** the grid shows the hours in which every gym is taken

### Requirement: Lessons without a room are counted, not hidden

The report MUST state how many lessons in the period have no room and MUST link to them, so that missing data does not read as an empty room.

#### Scenario: Unassigned lessons are named

- **GIVEN** 14 lessons in the week with only a free-text location
- **WHEN** the report runs for that week
- **THEN** it says 14 lessons have no room and links to their list

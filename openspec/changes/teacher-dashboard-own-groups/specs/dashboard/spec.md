## ADDED Requirements

### Requirement: The teacher dashboard of a group teacher lists only their own groups
When the user's primary role is `instructor`, the teacher dashboard's cohort list MUST show only cohorts whose `teacherIds` lists the user. Its session and assignment lists MUST show only rows whose `cohortId` is one of those cohorts. Its course list MUST show only the courses those cohorts run: their `courseId`, and the `courseIds` of their programme. Any other role that reaches the teacher view MUST keep the school-wide lists. A list whose scope is empty MUST show no rows and MUST NOT ask the server for an unfiltered list. Each list MUST show fields its schema declares, never a raw reference id.

#### Scenario: The teacher of Groep 7 sees Groep 7
@e2e exclude Dashboard list scoping; pinned by tests/unit-js/teacherScope.test.mjs (the scope is the cohorts the teacher teaches and the courses they run; each widget gets the filter of its schema), and checked live as po-leerkracht-09.
- **GIVEN** `po-leerkracht-09` teaches Groep 7 and no other group
- **WHEN** they open the teacher dashboard
- **THEN** "My cohorts" lists Groep 7 only
- **AND** the session and assignment lists show only Groep 7's rows
- **AND** the course list shows only the courses Groep 7 runs

#### Scenario: A coordinator keeps the school-wide view
@e2e exclude Dashboard list scoping; pinned by tests/unit-js/teacherScope.test.mjs (only a group teacher is scoped; school-wide roles get no filter), and checked live as po-ib-01 and po-directeur-01.
- **GIVEN** `po-ib-01` is a coordinator
- **WHEN** they open the teacher dashboard
- **THEN** the lists show every group in the school

#### Scenario: A teacher without a group sees empty lists
@e2e exclude Dashboard list scoping; pinned by tests/unit-js/teacherScope.test.mjs (a teacher without a group gets lists that match nothing, not the whole school).
- **GIVEN** a new teacher who teaches no group yet
- **WHEN** they open the teacher dashboard
- **THEN** the session, assignment and course lists are empty
- **AND** the dashboard never asks for those lists without a filter

#### Scenario: The lists show names, not ids
@e2e exclude Column choice; pinned by tests/unit-js/teacherScope.test.mjs (every column of the teacher widgets is a field the schema has).
- **WHEN** a teacher opens the teacher dashboard
- **THEN** the cohort list shows each group's name, period and status, not a programme uuid
- **AND** the session and assignment lists show each row's title

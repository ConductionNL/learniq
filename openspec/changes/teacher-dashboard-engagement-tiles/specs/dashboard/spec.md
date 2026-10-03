## ADDED Requirements

### Requirement: The engagement tiles of a group teacher count only their own groups
When the user's primary role is `instructor`, the teacher dashboard's "Avg. engagement score" and "Open engagement flags" tiles MUST aggregate only engagement rows whose `learnerId` is listed in `learnerIds` of a cohort whose `teacherIds` lists the user. Any other role that reaches the teacher view MUST keep the school-wide tiles. While the scope is loading, and when the scope holds no pupils, a tile MUST NOT ask the server for an unfiltered aggregate.

#### Scenario: The teacher of Groep 7 sees the engagement of Groep 7
@e2e exclude Dashboard tile scoping; pinned by tests/unit-js/teacherScope.test.mjs (an engagement tile of a group teacher counts only the pupils of their groups; the teacher view feeds both engagement tiles through the teacher scope), and checked live as po-leerkracht-09.
- **GIVEN** `po-leerkracht-09` teaches Groep 7 and no other group
- **WHEN** they open the teacher dashboard
- **THEN** "Avg. engagement score" averages the scores of Groep 7's pupils only
- **AND** "Open engagement flags" counts the open flags of Groep 7's pupils only

#### Scenario: A coordinator keeps the school-wide tiles
@e2e exclude Dashboard tile scoping; pinned by tests/unit-js/teacherScope.test.mjs (an engagement tile of a school-wide role keeps counting the whole school), and checked live as po-ib-01.
- **GIVEN** `po-ib-01` is a coordinator
- **WHEN** they open the teacher dashboard
- **THEN** both tiles aggregate every engagement row in the school

#### Scenario: A teacher without pupils gets no school-wide number
@e2e exclude Dashboard tile scoping; pinned by tests/unit-js/teacherScope.test.mjs (an engagement tile never asks for the whole school while the scope loads or is empty).
- **GIVEN** a teacher whose cohorts list no pupils, or who teaches no cohort
- **WHEN** they open the teacher dashboard
- **THEN** both tiles show no number
- **AND** the dashboard never asks for an unfiltered engagement aggregate

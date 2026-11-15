## ADDED Requirements

### Requirement: Every role that reads absence reports reaches them from the menu
The menu MUST offer the absence reports list (`ExcuseRequests`) exactly once to every primary role whose group `ExcuseRequest` grants read (`instructor`, `coordinator`, `administration-manager`, `compliance-officer`) and to `admin`, and to no other role. The entry MUST stay visible on every install that did not choose the `corporate` segment.

#### Scenario: A group teacher opens the absence reports from the menu
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (every role that reads absence reports finds them in the menu, once), built with the library's buildManifest and visibleIf evaluator.
- **GIVEN** `po-leerkracht-09` is a group teacher in a primary school
- **WHEN** they open the People group in the menu
- **THEN** it lists "Absence reports", which opens `/attendance/excuses`

#### Scenario: A compliance officer finds the reports under Compliance
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (every role that reads absence reports finds them in the menu, once).
- **GIVEN** a compliance officer, who does not see the People group
- **WHEN** they open the Compliance group
- **THEN** it lists "Absence reports"

#### Scenario: Roles without read access get no entry
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (roles the register does not let read a report get no entry; every school segment shows the entry).
- **GIVEN** a user whose primary role is `hr`, `team-lead`, `learner` or `guardian`, or a school that chose `corporate`
- **WHEN** they open the menu
- **THEN** no entry opens the absence reports

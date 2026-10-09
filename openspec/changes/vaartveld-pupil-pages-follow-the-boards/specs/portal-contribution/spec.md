## ADDED Requirements

### Requirement: The pupil pages follow the Vaartveld boards

The pupil's overview MUST open with the greeting and the week, put today's timetable in the main column with a link to the whole week in its heading, put homework and tests (four rows by due date) and the three newest grades (rows, newest first) in the side column each with a link under it, and show the absence of the school year as one strip with a link to the absence page. It MUST NOT carry buttons or a messages block, which the board does not show. The grades page MUST group the grades per subject with the average, mark a grade under 5,5, show how many subjects are at a pass, and offer the tabs Periode 1, Heel het schooljaar and Schoolexamen. Every new word MUST arrive in Dutch; a tab's filter values MUST NOT be translated.

#### Scenario: Noor's overview reads in two columns
- **GIVEN** the student contribution
- **WHEN** portaliq draws `studentOverview`
- **THEN** the timetable stands in the main column with "Hele week", homework and grades stand in the side column with "Alles van deze week" and "Alle cijfers", and the absence strip reads "ziek", "te laat" and "zonder melding"
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

#### Scenario: Noor's grades are grouped per subject
- **GIVEN** the student contribution
- **WHEN** portaliq draws `studentGrades`
- **THEN** one block groups the grades by subject with the summary on top and the tabs Periode 1, Heel het schooljaar and Schoolexamen, and the tab for Periode 1 filters on the stored period "1"
- @e2e exclude the grouped list needs portaliq PQ-MIJN (#1429) on the proof instance; covered by PHPUnit `PupilPagesFollowTheBoardsTest::testTheGradesAreGroupedPerSubject`

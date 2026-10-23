## ADDED Requirements

### Requirement: The student pages use the words of the boards

The student contribution MUST declare readable columns for `studentGrades` (subject, date, grade), each a projected field. The student menu MUST hold the BPV hours page as "BPV and hours". A week of hours in state `rejected` MUST read "Sent back". Dutch readers MUST see "BPV en uren" and "Teruggestuurd".

#### Scenario: The grades table has readable headers
- **GIVEN** a student signed in to her portal
- **WHEN** she opens her grades
- **THEN** the columns read Vak, Datum and Cijfer, and no field key is a header
- @e2e exclude declaration only, covered by PHPUnit `PortalContributionProviderTest::testStudentGradesHaveReadableColumns`

#### Scenario: A sent-back week says so
- **GIVEN** Milan's week 39 that Petra Bakker sent back
- **WHEN** Milan opens "BPV en uren" from his menu
- **THEN** the week's status reads "Teruggestuurd"
- @e2e exclude declaration only, covered by PHPUnit `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo` and `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`

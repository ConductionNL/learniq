## ADDED Requirements

### Requirement: A report card carries its subject grades in readable form
A ReportCard MUST carry `periodName`, the name of its ReportPeriod, and `gradeLines`, one line per `subjectGrades` entry: the subject's course name, else its curriculum plan name, then a colon and the period average with one decimal and a decimal comma ("Rekenen: 7,9"), or the name alone when the entry has no average. An entry whose subject has no name MUST be left out rather than shown as a code. The server MUST write both on every create and update and replace any value a caller sends. When the names cannot be read, an update MUST keep the stored values and a create MUST store none, and the write MUST go through.

#### Scenario: A composed report card reads as a period and one line per subject
@e2e exclude Server-side derivation; pinned by tests/Unit/Service/ReportCardGradeLinesTest.php and tests/Unit/Listener/ReportCardGradeLinesStampTest.php.
- **GIVEN** a report card for Rapport 1 with Rekenen 7.9 and Taal 8
- **WHEN** it is saved
- **THEN** `periodName` is "Rapport 1" and `gradeLines` is ["Rekenen: 7,9", "Taal: 8,0"]

#### Scenario: A recompose updates the lines and a sent value is replaced
@e2e exclude Server-side derivation; pinned by tests/Unit/Listener/ReportCardGradeLinesStampTest.php testAnUpdateFollowsTheGradesAndReplacesSentLines.
- **GIVEN** a stored report card with "Rekenen: 7,9"
- **WHEN** it is saved with Rekenen 6.4 and a made-up `gradeLines`
- **THEN** `gradeLines` is ["Rekenen: 6,4"]

#### Scenario: The example sets carry what the server derives
@e2e exclude Seed data; pinned by tests/Unit/Service/ReportCardGradeLinesTest.php testTheExampleSetsCarryWhatTheServerDerives.
- **WHEN** the po or vo example set is loaded
- **THEN** every report card already carries the `periodName` and `gradeLines` the server would derive

### Requirement: Existing report cards get their readable grades
A post-migration repair step MUST write `periodName` and `gradeLines` on every existing ReportCard whose stored values differ from the derived ones, without a session, without changing any other field or the lifecycle, and MUST save nothing on a second run.

#### Scenario: A report card composed before the stamp gets its lines on upgrade
@e2e exclude Repair step; pinned by tests/Unit/Repair/BackfillReportCardGradeLinesTest.php.
- **GIVEN** a published report card without `gradeLines`
- **WHEN** learniq is upgraded
- **THEN** the card carries its period name and grade lines and is still published to parents
- **AND** a second run saves nothing

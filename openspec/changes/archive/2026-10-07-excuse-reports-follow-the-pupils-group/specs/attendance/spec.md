## ADDED Requirements

### Requirement: An absence report is read by the teachers of the pupil's group and by school-wide staff
Every `ExcuseRequest` write MUST stamp `teacherIds` on the server with the teachers of the pupil's current groups: the `teacherIds` and `teacherAssignments[].teacherId` of every cohort whose `learnerIds` lists the report's `learnerId` and whose lifecycle is not `completed` or `archived`. A value the client sends MUST be replaced. When the lookup fails, a create MUST stamp an empty list, and an update whose pupil did not change MUST keep the stored list. A member of `instructors` MUST read and update only the reports whose `teacherIds` contains them. Members of `coordinators`, `administration-managers` and `compliance-officers` MUST read every report. Members of `coordinators` and `compliance-officers` MUST be able to update, and so approve or reject, every report.

#### Scenario: A parent's report reaches the group teacher and nobody else's
@e2e exclude Pre-write listener and register rule with no screen of their own; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAPortalReportListsTheTeachersOfThePupilsGroup) and tests/Unit/Settings/ExcuseRequestRegisterTest.php (testATeacherReadsOnlyTheReportsOfTheirOwnGroups), and checked live per role.
- **GIVEN** pupil `pupil-1` is in Groep 7, taught by `juf-7` with duo-partner `duo-7`, and was in last year's archived Groep 6 with `juf-6`
- **WHEN** a guardian reports `pupil-1` absent through the portal
- **THEN** the report is stored with `teacherIds` `["juf-7", "duo-7"]`
- **AND** `juf-7` sees it in the list of absence reports, and the teacher of Groep 8 does not

#### Scenario: Coordinators and directors see every report
@e2e exclude Register rule; pinned by tests/Unit/Settings/ExcuseRequestRegisterTest.php (testATeacherReadsOnlyTheReportsOfTheirOwnGroups), and checked live as a coordinator and a director.
- **GIVEN** absence reports for pupils in several groups
- **WHEN** a member of `coordinators` or `administration-managers` opens the list of absence reports
- **THEN** they see the reports of every group
- **AND** only the coordinator can approve or reject them

#### Scenario: Nobody adds themselves to a report's audience
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAClientCannotAddItselfToTheAudience).
- **GIVEN** a teacher of Groep 7
- **WHEN** they save a report for a pupil of Groep 8 with `teacherIds` `["juf-7"]`
- **THEN** the report is stored with the teachers of Groep 8 only

#### Scenario: A failed lookup never widens the audience
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAFailedGroupLookupStampsNobodyOnCreate, testAnUpdateKeepsTheStoredTeachersWhenTheLookupFails).
- **GIVEN** the cohorts cannot be read
- **WHEN** a report is created
- **THEN** it is stored with an empty `teacherIds`, so only school-wide staff see it
- **AND** an update of a report for the same pupil keeps the teachers it had

### Requirement: Existing absence reports get their group teachers
A repair step MUST stamp `teacherIds` on every existing `ExcuseRequest` the same way the write path does. It MUST save only reports whose stored teachers differ from the derived ones, so a second run saves nothing. A report whose lookup fails MUST be left as it was.

#### Scenario: An old report reaches the group teacher
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/BackfillExcuseRequestTeachersTest.php (testStampsWhatTheServerWouldStampToday).
- **GIVEN** a report for `pupil-1` written before this change, without `teacherIds`
- **WHEN** the app is upgraded
- **THEN** the report carries the teachers of `pupil-1`'s group

#### Scenario: A second run changes nothing
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/BackfillExcuseRequestTeachersTest.php (testASecondRunSavesNothing).
- **GIVEN** the repair step ran once
- **WHEN** it runs again
- **THEN** it saves no report

# School Structure — Programmes, Curriculum Plans, Cohorts, Sessions

## ADDED Requirements

### Requirement: A class x subject teacher join exists distinct from Cohort.teacherIds
The system MUST persist `SubjectTeacherAssignment` (`cohortId`, `courseId`, `teacherId`) as an OpenRegister object, distinct from `Cohort.teacherIds`. `Cohort.teacherIds` names who teaches the cohort generally; `SubjectTeacherAssignment` names who teaches a specific subject (`Course`) within that cohort.

#### Scenario: A VO class has a different teacher per subject
- **GIVEN** a `Cohort` (a VO class) with two `Course`s, wiskunde and Engels
- **WHEN** two `SubjectTeacherAssignment` objects are created, each naming the same `cohortId` but a different `courseId` and `teacherId`
- **THEN** both persist independently, and neither depends on or duplicates `Cohort.teacherIds`

### Requirement: Cohort declares a duo-partner role and working days per teacher
`Cohort` MUST declare `teacherAssignments` additively: an array of `{ teacherId, role, days }`, where `role` is `primary` or `duo-partner` and `days` is an array of weekday values. `Cohort.teacherIds` MUST remain unchanged.

#### Scenario: A PO groep has a main teacher and a duo-partner on named days
- **GIVEN** a `Cohort` (a PO groep)
- **WHEN** `teacherAssignments` is set with one entry `role: primary` covering Monday-Wednesday and one entry `role: duo-partner` covering Thursday-Friday
- **THEN** both entries persist, and `Cohort.teacherIds` (if also set) is unaffected

#### Scenario: A pre-existing Cohort without teacherAssignments is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `teacherAssignments` set
- **WHEN** it is read
- **THEN** `teacherAssignments` resolves to an empty array and `teacherIds` is unchanged

### Requirement: Staff is persisted as an OpenRegister record with roles, qualifications and working days
The system MUST persist `Staff` (`ncUserId`, `roles`, `qualifications`, `workingDays`) as an OpenRegister object, a plain resource-metadata schema with no lifecycle (same shape as `Room`).

#### Scenario: A staff member's roles, qualifications and working days are recorded
- **GIVEN** the `Staff` schema is registered
- **WHEN** a `Staff` object is created with `roles: ["teacher", "mentor"]`, one or more `qualifications`, and `workingDays`
- **THEN** all three persist on the `Staff` object

### Requirement: Frontend is declarative for Staff and SubjectTeacherAssignment
`Staff` and `SubjectTeacherAssignment` MUST render as manifest-declared index+detail page pairs (`Staff` under People in `src/manifest.d/people.json`; `SubjectTeacherAssignment` alongside Cohort in `src/manifest.d/learning.json`), and `CohortDetail` MUST surface an object-list widget of its `SubjectTeacherAssignment`s filtered by `cohortId`. No custom Vue view and no PHP CRUD controller.

#### Scenario: A coordinator sees which teacher covers which subject from the group page
- **GIVEN** `CohortDetail` for a VO class with two `SubjectTeacherAssignment`s
- **WHEN** the page renders
- **THEN** the subject-teacher roster widget lists both assignments, each linking to its own detail page

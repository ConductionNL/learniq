## ADDED Requirements

### Requirement: Each portal declares the chrome its board shows

Each example portal declaration MUST declare `accountLabel`, `footer.contact`, `authentication.signInPage`, and `public` as the first sign-in mode. The academy's MUST declare `residentMenu.cardLabel`. Every "Direct regelen" tile MUST use one of portaliq's line-icon names, and every public news item of the po portal MUST name its audience in `audienceLabel`. An existing portal MUST get these keys only where its own value is empty.

#### Scenario: Mijn Wilgenboom has its header button and contact column
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** portal `wilgenboom` holds `accountLabel` "Mijn Wilgenboom" and a footer contact column starting with "Wilgenlaan 12, Zuiddrecht"
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

### Requirement: The guardian menu lists each child with its group and teacher

`LearnerProfile.groupLabel` MUST hold the group of the pupil's newest active enrolment and, when that group's first teacher has a display name of their own, " · " and that name. It MUST be written whenever a learner profile is saved, and again after an enrolment of that pupil is created or changes group, state or group name. The write MUST happen after the request, and MUST NOT happen when the line did not move. The guardian's child page MUST declare `group` and `records.subtitleFields: [groupLabel]`, and the children cards and the switcher MUST show the line.

#### Scenario: Vera reads as "Groep 7 · Meester Daan"
- **GIVEN** Vera's active enrolment is in Groep 7, whose first teacher is named "Meester Daan"
- **WHEN** her profile is saved, or her enrolment changes group
- **THEN** her profile's `groupLabel` reads "Groep 7 · Meester Daan"
- @e2e exclude server stamp, covered by PHPUnit `LearnerGroupLabelTest`; the menu itself by `tests/e2e/po-parent-flows.spec.ts` (h)

### Requirement: A new grade reaches the guardian's inbox when it becomes visible

A grade notice MUST carry the child's `learnerRef` and the subject's `courseName`, and MUST NOT carry the grade. The guardian's `parentGradeInbox` and the pupil's `studentInbox` MUST declare `visibleFromField: visibleFrom`, so a notice whose moment lies ahead is not shown.

#### Scenario: A held-back grade waits
- **GIVEN** a grade published with `visibleFrom` tomorrow
- **WHEN** the guardian opens her messages today
- **THEN** the notice is not there; tomorrow it reads the subject's name
- @e2e exclude the hiding is portaliq's (#1198); the notice's fields are covered by PHPUnit `GradeRollupHandlerTest`

### Requirement: The absence form says in one sentence what the guardian reports

The guardian's `createExcuseRequest` MUST declare a `summary` naming only its own fields, with a phrase for every kind of absence, and a `confirmation` with a title.

#### Scenario: Sami is ill today
- **GIVEN** the guardian picks Sami, today and "Ziekte"
- **WHEN** she looks above the send button
- **THEN** she reads "U meldt" and "Sami is vandaag ziek."
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: The latest report reads as one bar per subject

Publishing a report card to parents MUST replace the pupil's `report-subject-grade` rows with one row per named subject of that card, in its order, carrying the caption and the teacher's words. The report card MUST NOT be written. The child's page MUST draw them with `display: bars`.

#### Scenario: Vera's June report
- **GIVEN** Vera's report 2 is published with Rekenen 7,9 to Wereldoriëntatie 8,2
- **WHEN** the guardian opens Vera's page
- **THEN** she sees six bars in that order and the teacher's words
- @e2e exclude row writer, covered by PHPUnit `ReportSubjectGradeRowsTest`; the bars render through portaliq's display

# Grading: final grade programme delta

## ADDED Requirements

### Requirement: The final grade roll-up writes the programme it belongs to

When the roll-up writes a `FinalGrade`, it MUST set `programmeId` to the id of the `Programme` whose `curriculumPlanId` is the grade's `curriculumPlanId`. When no programme uses that plan, `programmeId` MUST keep the value the row had, null for a new row.

#### Scenario: A final grade names the programme of its plan
@e2e exclude Listener write with no UI step of its own; pinned by tests/Unit/Listener/GradeRollupHandlerTest.php::testAFinalGradeNamesTheProgrammeOfItsPlan.
- **GIVEN** a programme with curriculum plan P
- **WHEN** a grade entry under plan P is published
- **THEN** the learner's final grade for P carries that programme's id as `programmeId`
- **AND** the programme page counts it

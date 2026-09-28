# Grading: pass rules, exemptions and final grade shape delta

## ADDED Requirements

### Requirement: Pass rules apply their declared minimum

For an `all-must-pass` CurriculumPlan, the pass verdict MUST compare each `passRules[]` entry's `minValue`, the property the register declares, and MUST NOT read any other key for the minimum. A rule with a `componentId` MUST be met by the learner's best published entry for that component. A rule with `componentId: null` MUST be met by the final value. A component whose best entry is an exemption MUST satisfy its rule without a numeric comparison.

#### Scenario: A component below its minimum fails the plan

- **GIVEN** an `all-must-pass` plan with rules `{componentId: "comp-a", minValue: 5.5}` and `{componentId: "comp-b", minValue: 5.5}`
- **AND** published entries `comp-a: 8.0` and `comp-b: 4.0`, so the average 6.0 clears a GradeScale threshold of 5.5
- **WHEN** the final grade is evaluated
- **THEN** `passed` is `false`

#### Scenario: Every component at or above its minimum passes

- **GIVEN** the same plan with entries `comp-a: 6.0` and `comp-b: 5.5`
- **WHEN** the final grade is evaluated
- **THEN** `passed` is `true`

#### Scenario: A final-grade rule compares the final value

- **GIVEN** an `all-must-pass` plan with the single rule `{componentId: null, minValue: 5.5}` and entries on components `comp-a: 7.0` and `comp-b: 6.0`
- **WHEN** the final grade is evaluated
- **THEN** `passed` is `true`, because the final value 6.5 meets 5.5
- **AND** with entries `comp-a: 5.0` and `comp-b: 5.0` and no GradeScale threshold, `passed` is `false`

### Requirement: A plan satisfied entirely by exemptions passes

When a learner's published entries on a plan are all exemptions, the final value MUST stay `null` and `passed` MUST be `true`, provided an exemption covers every component the plan declares. A plan that declares no components MUST pass on its exemption entries alone. A declared component without an exemption or a graded entry MUST keep `passed: null`.

#### Scenario: An exempted unit passes

- **GIVEN** a plan with the single component `unit-1` and one published entry for `unit-1` with `sourceKind: exemption`
- **WHEN** the final grade is evaluated
- **THEN** the FinalGrade has `value: null` and `passed: true`

#### Scenario: A partial exemption with a missing component stays open

- **GIVEN** a plan with components `unit-1` and `unit-2`, and only an exemption entry for `unit-1`
- **WHEN** the final grade is evaluated
- **THEN** `passed` is `null`

### Requirement: The final grade roll-up writes only declared properties

`GradeRollupHandler` MUST write only properties the `FinalGrade` schema declares. It MUST NOT write `cohortId`, which the schema does not declare and no reader uses. When it merges an existing FinalGrade that still carries `cohortId`, the saved object MUST NOT carry it either.

#### Scenario: A recomputed final grade carries no cohortId

- **GIVEN** a published GradeEntry with `cohortId: "cohort-1"`
- **AND** an existing FinalGrade for that learner and plan that still carries `cohortId`
- **WHEN** the roll-up recomputes the FinalGrade
- **THEN** the saved FinalGrade has no `cohortId` key
- **AND** it keeps `courseId`, `gradeScaleId` and `tenant_id`

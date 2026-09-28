# Study progress: exempted units earn credits delta

## ADDED Requirements

### Requirement: An exempted unit earns its study advice credits

A course whose final grade the exam board satisfied entirely by exemption MUST count toward the learner's earned ECTS credits in the binding study advice calculation, the same as a passed course. The credit MUST come from the FinalGrade's `passed: true`, which the grading roll-up writes for an exemption-only plan, so `BsaProgressEvaluator` keeps one rule: it sums the credits of passed final grades.

#### Scenario: A first-year exemption counts toward the advice

- **GIVEN** a programme course worth 5 ECTS whose plan has one component
- **AND** the learner's only entry on that plan is an exemption granted by the exam board
- **WHEN** the final grade is recomputed and the learner's study advice credits are evaluated
- **THEN** the FinalGrade has `passed: true`
- **AND** the course's 5 ECTS count in `ectsEarned`

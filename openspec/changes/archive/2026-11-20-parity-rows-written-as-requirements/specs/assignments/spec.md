## ADDED Requirements

### Requirement: A teacher sets an assignment with instructions and a hand-in date

An `Assignment` MUST carry a title, instructions, the course or group it belongs to and a `dueAt`. It MAY carry briefing materials, a rubric and whether late hand-in is allowed. It MUST start in `draft`, and learners MUST see it only once it is `published` through `AssignmentPublishGuard`.

#### Scenario: A learner sees a published assignment with its date

- **GIVEN** a teacher creates the assignment "Betoog schrijven" with instructions and a hand-in date of 14 March
- **WHEN** the teacher publishes it and a learner of the group opens it
- **THEN** the learner reads the instructions and the hand-in date

#### Scenario: A draft assignment stays with the teacher

- **GIVEN** the assignment "Betoog schrijven" in `draft`
- **WHEN** a learner lists assignments
- **THEN** the assignment is not returned

### Requirement: A teacher marks a submission against a rubric with weighted criteria

A `Rubric` MUST hold an ordered list of criteria, each with a label, a weight and achievement levels. The marking screen MUST show each criterion of the assignment's rubric, and a criterion's weight MUST set its share of the rubric's maximum points.

#### Scenario: A criterion with twice the weight counts twice

- **GIVEN** a rubric with "Structuur" weight 1 and "Argumentatie" weight 2
- **WHEN** a teacher marks a submission and picks the top level on both
- **THEN** "Argumentatie" contributes twice the points of "Structuur" to the mark

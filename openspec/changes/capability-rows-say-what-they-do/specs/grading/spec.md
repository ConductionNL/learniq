## ADDED Requirements

### Requirement: The final grade is the weighted average of its component marks

For a curriculum plan with `formula: weighted-average`, the final grade MUST be the sum of each mark times its effective weight, divided by the sum of those weights. Each component's value, weight and contribution MUST appear in `FinalGrade.breakdown.components`.

#### Scenario: Two marks with different weights

- **GIVEN** a plan with component A of weight 1 and component B of weight 3
- **AND** published marks of 6.0 for A and 8.0 for B
- **WHEN** the final grade is computed
- **THEN** the final grade is 7.5
- **AND** the breakdown shows A contributing 6.0 and B contributing 24.0

### Requirement: A learner sees what one more mark would do to their average

On their own grades, a learner MUST be able to enter a possible next mark with its weight and see what their average and final grade would become. The system MUST show this as a forecast and MUST NOT save it as a grade.

#### Scenario: A learner tries a possible next mark

- **GIVEN** learner Fenna with an average of 5.4 in a subject
- **WHEN** she enters a possible next mark of 7.0 with weight 2
- **THEN** the page shows the average it would become and whether she would then pass
- **AND** no grade is saved

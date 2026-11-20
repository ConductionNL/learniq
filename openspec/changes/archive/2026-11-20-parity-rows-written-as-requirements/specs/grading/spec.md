## ADDED Requirements

### Requirement: A grade shows what it does to the final grade

The grade impact page of a `GradeEntry` MUST show the grade's value, its effective weight, the points it contributes, the period average and the current final grade with its pass status. A grade that is not yet visible MUST show the date it becomes visible to the learner.

#### Scenario: A teacher opens the impact of one grade

- **GIVEN** a published grade of 7,5 in period 2 with weight 2
- **WHEN** the teacher opens its grade impact page
- **THEN** the page shows 7,5, the weight, the points contributed, the period 2 average and the current final grade

### Requirement: A teacher reads one grid of every learner and every component

The gradebook of a group MUST show one row per learner and one column per component of the group's plan, with each learner's mark per component and an average per column. A group without learners MUST say so.

#### Scenario: The gradebook of a class

- **GIVEN** a group of 24 learners whose plan has four components
- **WHEN** the teacher opens the group's gradebook
- **THEN** a grid of 24 rows and four columns shows the marks, with an average under each column

#### Scenario: An empty group

- **GIVEN** a group with no learners
- **WHEN** the teacher opens its gradebook
- **THEN** the page reads "No learners in this group."

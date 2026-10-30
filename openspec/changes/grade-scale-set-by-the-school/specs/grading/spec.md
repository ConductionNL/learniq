# grading Specification (delta)

## ADDED Requirements

### Requirement: A school defines its own grade scales

A user allowed to create grade scales SHALL be able to add, change and archive a `GradeScale` with a name and a kind: `numeric`, `percentage`, `letter`, `ects`, `pass-fail` or `band`. A numeric or percentage scale SHALL carry its lowest and highest value and its pass limit. A letter, ECTS or band scale SHALL carry its ordered bands, each with a value range and a pass flag. A curriculum plan SHALL name the scale its marks use, and every mark on that plan SHALL be read on that scale. learniq SHALL ship no fixed scale that a school must use.

#### Scenario: A school sets up the Dutch 1 to 10

- **GIVEN** an examinations officer
- **WHEN** they add the scale "Cijfer 1 tot 10" as numeric from 1 to 10 with a pass at 5.5 and publish it
- **THEN** a curriculum plan can name it, and its marks are read from 1 to 10

#### Scenario: A training company marks pass or fail

- **GIVEN** a training company with the scale "Behaald" of kind `pass-fail`
- **WHEN** a trainer marks a practical exam on it
- **THEN** the mark is recorded on "Behaald" and says passed or not passed

### Requirement: The scale decides how a final grade is rounded and whether it passes

The final grade SHALL be rounded by its scale's `roundingRule` before it is compared and stored: `none`, one decimal half up, a whole number half up, or the nearest half. On a numeric or percentage scale it SHALL pass when the rounded value is at or above the pass limit. On a letter, ECTS or band scale it SHALL pass when the band it falls in carries the pass flag. Pass rules a curriculum plan declares SHALL still apply on top.

#### Scenario: Rounding lifts a mark over the limit

- **GIVEN** a scale with a pass at 5.5 and rounding to one decimal half up
- **WHEN** a learner's weighted average is 5.45
- **THEN** the final grade is stored as 5.5 and passes

#### Scenario: A band without the pass flag

- **GIVEN** a letter scale where band E has no pass flag
- **WHEN** a learner's final grade falls in band E
- **THEN** the final grade does not pass

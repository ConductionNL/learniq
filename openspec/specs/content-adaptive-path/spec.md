# content-adaptive-path Specification

## Purpose
TBD - created by archiving change content-adaptive-next-step-and-preview. Update Purpose after archive.

## Requirements

### Requirement: Next step rules

The system MUST let a course author define ordered next step rules on a lesson and MUST resolve the next lesson for a learner from the first rule that matches the learner's result, else the lesson's default next lesson. A rule MUST only target a lesson of the same course. The resolver MUST use only results of the requesting learner.

#### Scenario: A learner who fails is sent to a refresher

- **GIVEN** a lesson with the rule score below 60 goes to Refresher and the default next is Module 2
- **WHEN** a learner completes the quiz with 45
- **THEN** the player offers Refresher as the next lesson

#### Scenario: A learner who passes continues

- **GIVEN** the same lesson
- **WHEN** a learner completes the quiz with 80
- **THEN** the player offers Module 2

#### Scenario: A rule cannot leave the course

- **GIVEN** an author saving a rule that targets a lesson of another course
- **WHEN** the author saves
- **THEN** the save is refused with the reason

## ADDED Requirements

### Requirement: A school loads a competency framework and marks against its outcomes

A team lead or compliance officer MUST be able to load a `CompetencyFramework` with its proficiency scale and its tree of `Competency` nodes, whose leaves are the learning outcomes. A published mark on work aligned to one of those outcomes MUST create or update the learner's `CompetencyAttainment` for that outcome.

#### Scenario: A teacher's mark lands on an outcome

- **GIVEN** a framework loaded with the kerntaak "Zorg verlenen" and its werkproces "Voert verpleegtechnische handelingen uit"
- **AND** an assignment aligned to that werkproces
- **WHEN** the teacher's mark for a learner's submission is published
- **THEN** a `CompetencyAttainment` exists for that learner and werkproces, with the grade entry in `gradeEntryIds`

### Requirement: A group view shows which skills are thin across a cohort

The skills-gap page MUST offer a view for a whole cohort. It MUST list each required competency with the number of learners in the cohort who have not yet reached the framework's target level, so a teacher sees where the group is thin.

#### Scenario: A teacher opens the skills gap of a class

- **GIVEN** cohort 3HV-B of 27 learners, where 15 have not reached the target level on "Argumenteren"
- **WHEN** the mentor opens the skills-gap view for 3HV-B
- **THEN** "Argumenteren" is listed with 15 of 27 learners below the target level

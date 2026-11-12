## ADDED Requirements

### Requirement: A teacher scores the same open question across every learner's attempt before the next question

The scoring page MUST show one open question at a time, with every submitted attempt's answer to it in one list, each with a score field up to the question's maximum. A teacher MUST be able to save the scores for that question and move to the next or previous open question. A score above the maximum, or below zero, MUST be refused before it is saved.

#### Scenario: A teacher marks question 3 for the whole class

- **GIVEN** a test with two open questions and 25 submitted attempts
- **WHEN** a teacher opens "Score open answers", which shows "Question 1 of 2", scores all 25 answers and saves
- **THEN** each attempt's `manualScore` for question 1 is stored
- **AND** after "Next question" the page shows question 2 with the 25 answers to it

#### Scenario: A score above the maximum is refused

- **GIVEN** an open question with a maximum score of 4
- **WHEN** the teacher enters 5 for one learner
- **THEN** the field says the score is higher than the maximum and that score is not saved

### Requirement: Each learner gets their own random draw from the item pool

For an assessment with `itemSelectionMode: random-draw`, the server MUST draw the items for each attempt on its own, so two learners starting the same assessment MUST be able to receive different sets of items. Each set MUST be stored in that attempt's `drawnItemRefs`.

#### Scenario: Two learners start the same random-draw test

- **GIVEN** an assessment that draws 5 items from a pool of 20 published items
- **WHEN** two learners each start an attempt
- **THEN** each attempt stores its own 5 drawn items in `drawnItemRefs`
- **AND** the two sets are drawn independently and need not match

### Requirement: Each learner sees the answer options in their own order

When `shuffleAnswerOptions` is true, the server MUST shuffle the movable answer options of each choice item per attempt, so two learners MUST be able to see the options of the same item in a different order. Options marked `fixed` MUST keep their place.

#### Scenario: Neighbours open the same question

- **GIVEN** an assessment with `shuffleAnswerOptions: true` and a choice item with four movable options
- **WHEN** two learners each start an attempt
- **THEN** each attempt stores its own `optionOrder` for that item
- **AND** the two orders are shuffled independently and need not match

### Requirement: Cronbach's alpha is computed once 30 graded attempts exist

Once an assessment with at least two items has 30 or more `graded` results, the system MUST compute `AssessmentReliability.cronbachAlpha` as a number and set `insufficientData` to false.

#### Scenario: The 30th graded attempt

- **GIVEN** an assessment with 10 items and 29 graded results
- **WHEN** a 30th result is graded
- **THEN** `AssessmentReliability` for that assessment carries a numeric `cronbachAlpha` and a `sampleSize` of 30
- **AND** `insufficientData` is false

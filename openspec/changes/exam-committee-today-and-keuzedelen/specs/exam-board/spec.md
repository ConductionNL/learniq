## ADDED Requirements

### Requirement: A student objects against a result, and the committee decides within its term

learniq MUST keep an `exam-objection` against one grade entry, with the moment it was submitted, the decision deadline from the exam regulation's term in working days, the examiner's response and the committee's decision with a rationale. A decision MUST NOT be saved without a rationale; a decision that changes the result MUST write the new result through the existing grade publish path and MUST leave the old one in the audit trail.

#### Scenario: Engels spreken
- **GIVEN** a student of MT3A objected on Friday 25 September, and the term is ten working days
- **WHEN** the committee opens the objection
- **THEN** it reads "beslissen voor vrijdag 9 oktober" and shows the examiner's response
- @e2e exclude staff screen; spec-only proposal

### Requirement: The committee establishes the results of a sitting

The results of an exam sitting MUST stay provisional until the exam committee establishes them; establishing MUST record who and when for all results of the sitting at once, and the committee's Today MUST count the results waiting per sitting.

#### Scenario: Fourteen results
- **GIVEN** 14 results from 3 sittings are not established
- **WHEN** a committee member opens Today
- **THEN** "Resultaten vaststellen" reads "14 resultaten uit 3 afnames"
- @e2e exclude as above

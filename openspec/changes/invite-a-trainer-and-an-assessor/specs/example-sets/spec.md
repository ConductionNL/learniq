## ADDED Requirements

### Requirement: The vocational set seeds an assessor with work to read

The vocational college set MUST seed one active `ExternalAssessor`, and at least two `PortfolioShare` rows granted to that assessor, each `active` and each naming a `Portfolio` of the same set. Every share MUST carry the readable copies the server stamps on a live save: the portfolio's own title and the learner's name. The portfolios MUST belong to students who are on a placement in the set, so the assessor reads about people the rest of the set knows.

#### Scenario: The assessor has candidates
- GIVEN the vocational set is loaded
- WHEN the assessor's portal reads the shares granted to him
- THEN he sees two candidates, each with the portfolio title and the date his access runs to
- @e2e exclude covered by tests/Unit/Settings/VocationalCollegeExampleSetTest.php

#### Scenario: The seeded rows pass the schemas that validate them
- GIVEN the seeded assessor, portfolios, entries and shares
- WHEN each is validated against its shipped fragment
- THEN each is accepted, and a share without its portfolio is refused
- @e2e exclude covered by VocationalCollegeExampleSetTest::testTheNewRowsPassTheRealSchemas

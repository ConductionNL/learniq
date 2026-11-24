## ADDED Requirements

### Requirement: A school asks a guardian for permission, and the answer is recorded

A school MUST be able to ask the guardians of a cohort or of named learners for permission, with an explanation, the date it is about and a deadline to answer by. A guardian MUST be able to answer yes or no for her own children and for nobody else's, and the answer MUST record who answered, when, and at which assurance level.

#### Scenario: A guardian answers for her own child
- GIVEN the school asked permission for the trip of Vera's group, to answer by 16 October
- WHEN Fatima answers yes for Vera
- THEN one response is stored for Vera, naming Fatima, the moment and the assurance of her session
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Another child is not hers to answer for
- GIVEN the same request covers a classmate
- WHEN Fatima answers for that classmate
- THEN the answer is refused and nothing is stored
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: The request is on her list of things to do
- GIVEN the request is open and she has not answered
- WHEN she opens her overview
- THEN it is among the things she still has to do, with its deadline
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: Silence is not permission

A request MUST state whether an unanswered request counts as a refusal, and MUST default to counting it as one. A closed request MUST NOT accept an answer.

#### Scenario: Nobody answered
- GIVEN the deadline has passed and a child's guardians answered nothing
- WHEN the teacher reads the outcome
- THEN that child reads as not answered, and counts as refused
- @e2e exclude asserted from the caller in the service's unit tests

#### Scenario: An answer after the deadline
- GIVEN the request is closed
- WHEN a guardian answers
- THEN it is refused, and the refusal says the request is closed
- @e2e exclude as above

### Requirement: A teacher sees who has not answered

A teacher MUST be able to read, per request, which children's guardians answered yes, which answered no, and which did not answer at all.

#### Scenario: The evening before the trip
- GIVEN eighteen of twenty-eight guardians have answered
- WHEN the teacher opens the request
- THEN she reads the three groups, and the ten who have not answered are named
- @e2e exclude a staff screen; asserted in the view's own tests

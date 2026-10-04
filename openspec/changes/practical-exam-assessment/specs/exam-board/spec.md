## ADDED Requirements

### Requirement: An assessor is linked to an exam sitting by a record

An external assessor's access to an exam MUST come from an `ExamAssessorAssignment` naming the sitting, the assessor, the candidate and the assessor's role. The portal MUST scope every exam list by that assignment, and a sitting without an assignment for the caller MUST resolve nothing — including its documents.

#### Scenario: His day lists only his own candidates
- GIVEN Ruud is assigned to three sittings today and another assessor to a fourth
- WHEN he opens his portal
- THEN he sees his three, each with its time, candidate, exam and room, and not the fourth
- @e2e tests/e2e/assessor-flows.spec.ts

#### Scenario: A sitting he is not assigned to grants nothing
- GIVEN a sitting with no assignment for Ruud
- WHEN he asks for it or for its documents by id
- THEN both are refused
- @e2e tests/e2e/assessor-flows.spec.ts

### Requirement: A practical exam assessment records who assessed and how sure the school is

A `PracticalExamAssessment` MUST record the assessor it came from and the assurance level of the session it was written in, the way a werkproces assessment does. The server MUST write both from the assertion; a value sent for either MUST be replaced.

#### Scenario: An invited assessor fills the form
- GIVEN Ruud signed in with the account the school gave him, so his session is `low`
- WHEN he submits his assessment of Daan
- THEN the row names him and records `assuranceLevel: basic`
- @e2e tests/e2e/assessor-flows.spec.ts

#### Scenario: The form loses nothing when the connection drops
- GIVEN Ruud has filled three of five criteria
- WHEN the connection drops and he returns
- THEN his draft is there, with the three criteria he scored
- @e2e exclude a dropped connection is asserted in the endpoint's unit tests; the e2e covers the completed form

### Requirement: A practical exam result needs both assessors

A practical exam assessment MUST NOT reach the exam board until both the first and the second assessor have signed it, and each signature MUST record the assurance level it was made at.

#### Scenario: One signature is not enough
- GIVEN only the first assessor has signed
- WHEN the exam board asks for the results of the day
- THEN that assessment is not among them
- @e2e exclude asserted from the caller in the exam-board service's unit tests

#### Scenario: Both have signed
- GIVEN both assessors have signed the same assessment
- WHEN the exam board asks again
- THEN the result is there, with both signatures and the assurance level of each
- @e2e exclude as above

## ADDED Requirements

### Requirement: An absence report names its pupil's learner profile

`ExcuseRequest` MUST require `learnerRef`, the learner profile of the pupil whose absence it reports, and MUST NOT accept a null value. Every create path MUST send it before OpenRegister validates: the pupil's portal action through its scope field, the guardian's action as a required field, and the staff form from the picked pupil.

#### Scenario: A report without the pupil is refused
- GIVEN an absence report with dates, a reason and a kind, but no `learnerRef`
- WHEN it is validated against the shipped `excuse-request` schema
- THEN it is refused, and the same report with the pupil's `learnerRef` is accepted
- @e2e exclude schema contract; covered by tests/Unit/Settings/RequiredLearnerRefRegisterTest.php (testTheFragmentsAcceptThePupilAndRefuseItsAbsence)

#### Scenario: The guardian reads "Kind" without "niet verplicht"
- GIVEN the guardian's absence form on the Wilgenboom site
- WHEN she opens it
- THEN the child field is labelled "Kind", required, and the dates are groups of Dag, Maand and Jaar
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: Stored rows get the pupil's learner profile before it is required

The upgrade MUST write `learnerRef` on every stored absence report and conference signup that lacks it, resolved from `learnerId`, before any other repair step writes those rows. A row whose pupil has no learner profile MUST be counted and left as it is. A second run MUST save nothing.

#### Scenario: An old report gets its pupil
- GIVEN a stored absence report with a `learnerId` and no `learnerRef`
- WHEN the upgrade runs
- THEN the report carries the pupil's learner profile, and a second run saves nothing
- @e2e exclude repair step; covered by tests/Unit/Repair/BackfillRequiredLearnerRefsTest.php

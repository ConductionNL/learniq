## ADDED Requirements

### Requirement: A conference signup names its pupil's learner profile

`ConferenceSignup` MUST require `learnerRef` and MUST NOT accept a null value. The guardian's direct booking and preference request MUST send it as a required field, and the staff booking view MUST send the picked pupil's learner profile. `slotId` and `conferenceRoundId` MUST stay optional until Ruben decides how a preference request and a direct booking supply them.

#### Scenario: A staff booking names the pupil
- GIVEN a staff member who picks a round, a pupil and a teacher
- WHEN she books
- THEN the request body carries the pupil's learner profile as `learnerRef`
- @e2e exclude request body; covered by tests/unit-js/conferenceSignupBody.test.mjs

#### Scenario: A signup without the pupil is refused
- GIVEN a booking with a time but no `learnerRef`
- WHEN it is validated against the shipped `conference-signup` schema
- THEN it is refused, and the same booking with the pupil's `learnerRef` is accepted
- @e2e exclude schema contract; covered by tests/Unit/Settings/RequiredLearnerRefRegisterTest.php (testTheFragmentsAcceptThePupilAndRefuseItsAbsence)

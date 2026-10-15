## ADDED Requirements

### Requirement: A pupil is addressed with je

Every Dutch string of the pupil's and student's portal MUST address her with "je", never "u" or "uw".

#### Scenario: Milan reports an absence
- **GIVEN** Milan reads his portal in Dutch
- **WHEN** he opens the absence form
- **THEN** it reads "Eerste dag dat je afwezig bent", not "u"
- @e2e exclude covered by PHPUnit `PupilWordingTest::testThePupilReadsJe`

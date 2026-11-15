## ADDED Requirements

### Requirement: An invited trainer may submit a werkproces assessment

The portal action `createWerkprocesAssessment` MUST accept a session at `minTrust: low`, and MUST post to learniq's own endpoint, because only a forwarded request carries the assertion that names the session's assurance. The endpoint MUST refuse a request whose assertion is missing, forged or of another audience, and MUST take the assessor from the assertion's claim, never from the body. It MUST refuse a placement that is not the trainer's own.

#### Scenario: A trainer who signed in from her invitation assesses
- GIVEN Karin signed in without eHerkenning, so her session is `low`
- WHEN she submits an assessment for her own student
- THEN it is stored, and the answer names the assurance it was stored with
- @e2e exclude portal forward; covered by tests/Unit/Service/Portal/PortalWerkprocesAssessmentTest.php and tests/Unit/Controller/PortalWerkprocesControllerTest.php

#### Scenario: A forged assertion writes nothing
- GIVEN a request whose assertion was signed with another secret
- WHEN it reaches the endpoint
- THEN it is refused and nothing is read or written
- @e2e exclude covered by PortalWerkprocesControllerTest::testAForgedAssertionIsRefused, which runs the real verifier

#### Scenario: Another trainer's placement is refused
- GIVEN a placement whose praktijkopleider is somebody else
- WHEN Karin submits an assessment for it
- THEN it is refused and nothing is written
- @e2e exclude covered by PortalWerkprocesAssessmentTest::testOnlyHerOwnPlacementIsAccepted

### Requirement: An assessment records who assessed and how sure the school is

Every werkproces assessment written from the portal MUST carry `assessorId`, `assessorName`, `assessorCompany`, `assessorCompanyKvkNumber` and `assuranceLevel`. The server MUST copy the four identity values from the `Praktijkopleider` record the assertion's subject resolves to, and MUST write `assuranceLevel` from the session: `basic` for a `low` session, `substantial` for a `substantial` one, `high` for `high`. A value a client sends for any of them MUST be dropped. `assuranceLevel` MUST use the same vocabulary as `PokSignature.assuranceLevel`.

#### Scenario: The row says who assessed, for which company
- GIVEN Karin Smit of Installatiebedrijf Van Dam, KvK 12345678
- WHEN she submits an assessment
- THEN the stored row names her, her company and its KvK number
- @e2e exclude covered by PortalWerkprocesAssessmentTest::testAnInvitedTrainerMayAssessAndTheRowSaysWho

#### Scenario: A client cannot dress up its own evidence
- GIVEN a submit that sends another assessor, another company and `assuranceLevel: high`
- WHEN the server stores it
- THEN the row names the assertion's trainer and the assurance her session reached
- @e2e exclude covered by PortalWerkprocesAssessmentTest::testAClientCannotDressUpItsOwnEvidence

#### Scenario: The stored row passes the shipped schema
- GIVEN an assessment as the endpoint stores it
- WHEN it is validated against the shipped `werkproces-assessment` fragment
- THEN it is accepted, and the same row with an invented assurance is refused
- @e2e exclude covered by PortalWerkprocesAssessmentTest::testTheStoredRowPassesTheRealSchema

### Requirement: A school may still demand an eHerkenning sign-in

`bpv_assessment_min_assurance` MUST set the lowest assurance a school accepts for a werkproces assessment, defaulting to `basic`. A submit below the floor MUST be refused before anything is written, and the answer MUST name the level required. An unknown value MUST read as `basic`, never as a stricter floor a school did not set.

#### Scenario: A school that demands eHerkenning
- GIVEN `bpv_assessment_min_assurance` is `substantial`
- WHEN a trainer on a `low` session submits
- THEN the submit is refused, says `substantial` is required, and writes nothing
- AND the same trainer on an eHerkenning session succeeds
- @e2e exclude covered by PortalWerkprocesAssessmentTest::testASchoolMayStillDemandEherkenning

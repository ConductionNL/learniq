# certification Specification

## ADDED Requirements

### Requirement: Staff reissue every certificate of a course in one action

Users in `hr` or `compliance-officers` MUST be able to reissue every `issued` credential of a course from the course page, with a required reason, after a preview that shows how many credentials will be reissued and how many revoked or expired ones are left alone. The work MUST run as a queued job, MUST be idempotent per run, and MUST NOT stop at one failed credential.

#### Scenario: A compliance officer reissues after the wording changed

- **GIVEN** the course "BHV basisopleiding" with twelve issued, one revoked and one expired certificate
- **WHEN** a compliance officer opens the course, chooses "Reissue certificates", reads "12 certificates will be reissued, 2 are left alone", enters the reason and confirms
- **THEN** the twelve issued certificates carry new signed content built from the current course
- **AND** the revoked and the expired certificate are unchanged

#### Scenario: An instructor cannot start a reissue

<!-- @e2e exclude Access rule on an endpoint; covered by CredentialReissueControllerTest::testInstructorIsRefused. -->

- **GIVEN** a user in `instructors` only
- **WHEN** they post to `POST /api/courses/{courseId}/credentials/reissue`
- **THEN** the request is refused and no job is queued

### Requirement: A reissue keeps who and when and records why

A reissue MUST NOT change a credential's `id`, `learnerId`, `courseId`, `issuedAt`, `expiresAt` or `kind`. It MUST set `reissuedAt`, `reissuedBy` and `reissueReason`, increase `reissueCount`, and leave an entry in the credential's audit trail.

#### Scenario: The certificate's history shows the reissue

- **GIVEN** a certificate issued on 3 March and reissued on 20 September with the reason "Nieuwe tekst certificaat na wijziging NIBHV-eisen"
- **WHEN** an HR officer opens the certificate
- **THEN** it still shows 3 March as the issue date
- **AND** its history shows the reissue on 20 September, by whom and why

### Requirement: A learner hears that their certificate was reissued

Each learner whose certificate was reissued in a run MUST get one notification with a link to the certificate. A certificate that had been offered to the EUDI wallet MUST get its wallet offer status cleared, so staff can offer the new version.

#### Scenario: A learner is told

<!-- @e2e exclude Notification delivery through the register dialect; covered by the gate-18 dialect check and CredentialReissueServiceTest. -->

- **GIVEN** a learner with one certificate in a reissue run
- **WHEN** the run reissues it
- **THEN** the learner gets one notification naming the course, linking to the certificate

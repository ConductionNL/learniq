## ADDED Requirements

### Requirement: An invited guardian gets a mail with a one-time link (REQ-PID-005)

After learniq has provisioned the guardian's portal account and written the `learniq.guardianRef` claim (REQ-PID-004), it MUST ask portaliq to mail the guardian a one-time link by dispatching `PortalAccountInvitationRequestedEvent` with its own app id and the account's `subjectRef`. Learniq MUST NOT ask for the mail when the claim was refused. The invite answer MUST carry `invitation` with `sent`, `not-sent` or `unavailable`. When portaliq does not ship the event, learniq MUST dispatch nothing for the mail and MUST still answer `invited`, with `invitation: unavailable`. Learniq MUST NOT receive, store or show the link or its secret.

#### Scenario: The school invites a guardian and she gets a mail
- **GIVEN** a guardian with the role `parent` and a portaliq that sends invitation mails
- **WHEN** the school's administration invites her with a verified address
- **THEN** the answer is `invited` with `invitation: sent`, and the event was dispatched for her account under the app id `learniq`
- @e2e exclude the link leaves by mail only; covered by PHPUnit `GuardianPortalInvitationTest::testAGuardianIsProvisionedAndLinked` and checked live on a test instance

#### Scenario: The mail does not leave
- **GIVEN** a portaliq whose mail server refuses the mail
- **WHEN** the school invites a guardian
- **THEN** the answer is `invited` with `invitation: not-sent`, and the guardian is still linked
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testAMailThatDidNotLeaveIsReportedAndTheGuardianStaysLinked`

#### Scenario: An older portaliq sends no mail
- **GIVEN** a portaliq without `PortalAccountInvitationRequestedEvent`
- **WHEN** the school invites a guardian
- **THEN** the answer is `invited` with `invitation: unavailable`, and only the provision and the claim were dispatched
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testAnOlderPortaliqLinksTheGuardianWithoutAMail`

#### Scenario: No mail without the claim
- **GIVEN** a portaliq that refuses the claim
- **WHEN** the school invites a guardian
- **THEN** the answer is `claim-refused` and no mail was asked for
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testNoMailIsAskedForWhenTheClaimWasRefused`

## ADDED Requirements

### Requirement: The school can invite a guardian with a code in a letter (REQ-PID-006)

The invitation of REQ-PID-004 MUST take a channel, `mail` or `letter`, with `mail` as the default. On `letter` learniq MUST dispatch `PortalAccountInvitationRequestedEvent` with the channel `letter` instead of asking for a mail, and MUST answer `invitation: code` with the code portaliq made and its expiry. Learniq MUST NOT store the code. Any other channel MUST be refused with `channel-unknown` before anything is dispatched. When portaliq makes no code, learniq MUST answer `invited` with `invitation: unavailable`. The code MUST only be answered to a caller who may invite.

#### Scenario: The administration invites a guardian by letter
- **GIVEN** a guardian with the role `parent` and a portaliq that makes invitation codes
- **WHEN** the school's administration invites her on the channel `letter`
- **THEN** the answer is `invited` with `invitation: code`, a code and its expiry, and no mail was asked for
- @e2e exclude the code is handed over on paper; covered by PHPUnit `GuardianPortalInvitationTest::testALetterAnswersTheCodeToPrint` and checked live on a test instance

#### Scenario: A mailed invitation carries no code
- **GIVEN** the default channel
- **WHEN** the school invites a guardian
- **THEN** the answer has no `code`
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testAMailedInvitationCarriesNoCode`

#### Scenario: An older portaliq makes no code
- **GIVEN** a portaliq whose invitation event has no channel
- **WHEN** the school invites a guardian on the channel `letter`
- **THEN** the answer is `invited` with `invitation: unavailable`
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testALetterWithoutACodeSaysSoAndTheGuardianStaysLinked`

#### Scenario: An unknown channel is refused
- **GIVEN** a caller who may invite
- **WHEN** they invite on the channel `sms`
- **THEN** the answer is `channel-unknown` with status 400 and nothing is dispatched
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testAnUnknownChannelIsRefusedWithoutDispatching`

### Requirement: An invitation records who issued it, into the caller's own organisation (REQ-PID-007)

Learniq MUST record every invitation it issues, on either channel, in Nextcloud's audit log through `CriticalActionPerformedEvent` and in the app log, with the issuer (the staff user's uid, or `occ` for the command), the guardian's reference, the channel and the organisation. The record MUST NOT contain the code or the link. A refused invitation MUST record nothing. The endpoint MUST refuse with `403 organisation-not-yours`, before anything is dispatched, an organisation slug that is not the slug of an OpenRegister organisation the caller belongs to; when OpenRegister cannot answer, every slug MUST be refused.

#### Scenario: The issuer is recorded and the code is not
- **GIVEN** a member of the administration of `de-wilgenboom`
- **WHEN** they invite a guardian by letter
- **THEN** the audit log holds one line naming them, the guardian, `letter` and `de-wilgenboom`, and no part of the code
- @e2e exclude an audit log line; covered by PHPUnit `GuardianPortalInvitationTest::testWhoIssuedTheInvitationIsRecordedWithoutTheCode` and checked live on a test instance

#### Scenario: Another school's portal is refused
- **GIVEN** a member of the administration who belongs to the OpenRegister organisation `de-wilgenboom` only
- **WHEN** they invite a guardian into `vaartveld-college`
- **THEN** the answer is `403 organisation-not-yours` and nothing is dispatched
- @e2e exclude covered by PHPUnit `PortalGuardianControllerTest::testTheOrganisationMustBeTheCallersOwnAndTheIssuerIsRecorded` and `CallerOrganisationsTest`, and checked live on a test instance

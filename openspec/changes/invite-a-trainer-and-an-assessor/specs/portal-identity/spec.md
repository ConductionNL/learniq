## ADDED Requirements

### Requirement: A school invites a trainer or an assessor it already created

learniq MUST offer `occ learniq:portal:invite-trainer` and `occ learniq:portal:invite-assessor`. Each MUST resolve the uuid to an active row of its own schema (`praktijkopleider`, `external-assessor`) and MUST refuse a uuid that names none, or a row the school switched off. It MUST NOT create the person. It MUST invite the address on that row unless the caller gives another, and MUST refuse anything that is not an address. It MUST ask portaliq for an account on the role's audience and then write the role's claim on it: `practicalTrainerId` for a trainer, `externalAssessorId` for an assessor.

#### Scenario: A trainer is invited and can be scoped
- GIVEN the school created Karin Smit as a praktijkopleider with her work address
- WHEN an administrator invites her for the school's portal organisation
- THEN portaliq provisions an account on the `praktijkopleider` audience with that address
- AND learniq writes `practicalTrainerId` on it, naming her own record
- @e2e exclude covered by tests/Unit/Portal/BpvPortalInvitationTest.php

#### Scenario: An invitation never mints a person
- GIVEN a uuid that names nobody, or a person the school switched off
- WHEN an administrator invites it
- THEN the invitation is refused and no account is asked for
- @e2e exclude covered by BpvPortalInvitationTest::testAnInvitationNeverMintsAPerson

#### Scenario: The claim is the one the portal scopes by
- GIVEN the claims the two invitations write
- WHEN they are compared with the `scopeClaim` of every collection of those audiences
- THEN they are the same, so an invited account can never read nothing for want of a claim
- @e2e exclude covered by BpvPortalInvitationTest::testTheClaimsMatchWhatTheContributionsScopeBy

#### Scenario: Without portaliq the invitation says so
- GIVEN portaliq is not installed, so its events do not exist
- WHEN an administrator invites a trainer
- THEN the invitation answers `portal-unavailable` rather than throwing
- @e2e exclude covered by BpvPortalInvitationTest::testWithoutPortaliqItSaysSo

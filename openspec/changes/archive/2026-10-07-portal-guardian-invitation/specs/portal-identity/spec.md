## ADDED Requirements

### Requirement: The school links a guardian to the parent portal (REQ-PID-004)

Learniq MUST let the school's administration (members of `admin` or `administration-managers`) invite a guardian to the portal with an email address the school verified. The invitation MUST provision a pending portal account (audience `parent`) through portaliq's `PortalAccountProvisionRequestedEvent` with that address marked verified, and MUST then write the claim `learniq.guardianRef` = the guardian's LearnerProfile uuid through `PortalAccountClaimRequestedEvent`. Only a LearnerProfile with the role `parent` MAY be invited. Without portaliq the invitation MUST dispatch nothing and answer `portal-unavailable`. Any other caller MUST get 403.

#### Scenario: An invited guardian sees their own child after signing in
- **GIVEN** the po example set, and guardian Fatima Hulstkamp invited with a verified address
- **WHEN** she signs in to the portal with DigiD at level substantial using that address
- **THEN** "My children" lists Vera Hulstkamp and no other child
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A teacher cannot invite a guardian
- **GIVEN** a user in `instructors` only
- **WHEN** they call the invite endpoint
- **THEN** the answer is 403 and nothing is provisioned
- @e2e exclude authorization guard, covered by PHPUnit `PortalGuardianControllerTest::testATeacherMayNotInvite`

#### Scenario: Only a guardian profile can be invited
- **GIVEN** a LearnerProfile with the role `learner`
- **WHEN** the school invites it
- **THEN** the answer is `guardian-unknown` and nothing is dispatched
- @e2e exclude covered by PHPUnit `GuardianPortalInvitationTest::testBadInputIsRefusedWithoutDispatching`

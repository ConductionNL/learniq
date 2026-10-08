---
capability: portal-identity
status: in-progress
built_by: openspec/changes/portal-identity
---

# portal-identity Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- [portal-identity](../../changes/portal-identity/) _(active)_ — additive UUID domain-object scope refs (`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) on a first slice of eight schemas (kind: config)

## Purpose

Scholiq's record schemas carry UUID **domain-object** scope references
(`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) alongside
their existing Nextcloud-uid properties, so the ADR-046 external portal can
scope portal subjects to their own records without ever touching a Nextcloud
user id (amendment A4). The references are additive, optional, and fail-closed.
This capability is the head of the portal chain — `portal-contribution` depends
on it.

## Requirements

Detailed requirements (REQ-PID-001 … REQ-PID-003) are defined in the active
change's delta spec —
[`openspec/changes/portal-identity/specs/portal-identity/spec.md`](../../changes/portal-identity/specs/portal-identity/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Scholiq exposes ADR-046 domain-UUID portal scope refs (REQ-PID-000)

The first portal slice MUST scope every portal-exposed record by a UUID
domain-object reference, never a Nextcloud user id (ADR-046 A4). Each schema in
the slice (`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`,
`Submission`, `ExcuseRequest`, `LearnerProfile`, `GradeNotification`) carries a
new `*Ref` UUID property alongside — never replacing — its existing
Nextcloud-uid property, additive and optional so existing objects stay valid
and unset refs are fail-closed (invisible to the portal).

#### Scenario: Every slice schema carries an additive UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN each schema in the first portal slice defines a `*Ref` property with `format` `uuid` (on the item for arrays)
- AND its original Nextcloud-uid property is still present and no new ref is `required`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Learner-scoped record schemas expose a UUID domain ref (REQ-PID-001)

The learner-scoped record schemas MUST each expose a UUID domain-object scope
reference alongside their existing Nextcloud-uid property (ADR-046 A4).
Specifically, `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` in
`lib/Settings/scholiq_register.json` each define a `learnerRef` property
(`type: string`, `format: uuid`, title "Learner Ref") whose value is the UUID
of the learner's `LearnerProfile` object — the portal-subject scope key,
distinct from the Nextcloud-uid `learnerId`. `Submission` defines a
`learnerRefs` array (items `format: uuid`, title "Learner Refs") alongside its
Nextcloud-uid `learnerIds`. The refs are additive: the Nextcloud-uid properties
stay unchanged and no new ref appears in a `required` list, so every existing
object stays valid with the ref absent.

#### Scenario: Learner-scoped schemas carry the UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` each define `learnerRef` with `type` `string` and `format` `uuid`
- AND `Submission` defines `learnerRefs` as an array whose items have `format` `uuid`
- AND each schema still defines its original `learnerId` / `learnerIds` property and lists neither new ref as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Parent linkage and submitter use UUID domain refs (REQ-PID-002)

`LearnerProfile` MUST define a `guardianRefs` array (items `format: uuid`,
title "Guardian Refs") alongside the unchanged Nextcloud-uid `parentIds`, so
the portal can resolve a parent subject to that parent's learner(s) via a
one-hop join. `ExcuseRequest` MUST define both `learnerRef` (uuid) and
`submittedByRef` (uuid) alongside the unchanged `learnerId` and `submittedBy`,
so a parent-audience create can be scope-stamped by the guardian domain UUID
without touching a Nextcloud user id. Neither new ref may be `required`.

#### Scenario: Guardian and submitter refs are present and additive

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `LearnerProfile` defines `guardianRefs` (array of uuid items) and still defines `parentIds`
- AND `ExcuseRequest` defines `learnerRef` (uuid) and `submittedByRef` (uuid) and still defines `learnerId` and `submittedBy`
- AND none of `guardianRefs`, `learnerRef`, `submittedByRef` is listed as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate and the provider register-drift-pin PHPUnit test

### Requirement: Versions bump for the version-gated import (REQ-PID-003)

Because OpenRegister's import is version-gated, the register `info.version` MUST
be bumped from `0.2.0` to `0.3.0` and every touched schema version
(`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`, `Submission`,
`ExcuseRequest`, `LearnerProfile`, `GradeNotification`) MUST be bumped from
`0.1.0` to `0.2.0` in the same change. `GradeNotification` MUST also define
`learnerRef` (uuid, title "Learner Ref") alongside its `learnerId` and
`recipient`, so the portal can scope a learner inbox. The register MUST remain
valid JSON.

#### Scenario: Register and schema versions are bumped and the file is valid

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `info.version` is `0.3.0` and each of the eight touched schemas has version `0.2.0`
- AND `GradeNotification` defines `learnerRef` with `format` `uuid`
- AND the file loads without error via `python3 -c "import json; json.load(...)"`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) run in CI

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

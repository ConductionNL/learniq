# Tasks: a guardian books a parent-teacher conversation in the parent portal

## 1. Register
- [x] 1.1 `ConferenceRound.invitedLearnerRefs`; `ConferenceInvitationAction` on `send-invitations`. Verify: PHPUnit `ConferenceInvitationActionTest`.
- [x] 1.2 `ConferenceSignup.required` = `conferenceRoundId`; `ConferenceReport` authorization adds `instructors`. Verify: live, the teacher records a report.

## 2. Portal booking
- [x] 2.1 `ConferenceSignupPortalStamp`, wired on create. Verify: PHPUnit `ConferenceSignupPortalStampTest` (stamp, teacher defaulting, three refusals, signed-in write untouched, registrar wiring).
- [x] 2.2 Parent collections and `createConferenceSignup`, child picker and cross reference on both parent actions, readable columns. Verify: PHPUnit `PortalContributionProviderTest::testParentBooksAConferenceForTheirOwnChildOnly`.

## 3. News audience
- [x] 3.1 `guardianAudience` and `parentGroupMemberships`. Verify: PHPUnit `PortalContributionProviderTest::testParentDeclaresTheNewsAudience`.

## 4. Live
- [x] 3.1 Teacher opens a round, guardian books in the portal, schedule is generated, teacher confirms and completes the slot and records the report. Verify: `tests/e2e/po-parent-flows.spec.ts`.

# Tasks: the guardian gets a mail when the school invites her

- [x] 1.1 `GuardianPortalInvitation`: after the claim lands, dispatch portaliq's `PortalAccountInvitationRequestedEvent` under learniq's own id and report `invitation: sent | not-sent | unavailable`; ask for no mail when the claim was refused; keep working with a portaliq that lacks the event. Verify: PHPUnit `GuardianPortalInvitationTest`.
- [x] 1.2 `occ learniq:portal:invite-guardian` reports the mail in one line. Verify: live on a test instance.
- [x] 1.3 Live with portaliq's change checked out: the invite mails a link, the guardian signs in without an address, follows the link and sees her child. Verify: live on a test instance.

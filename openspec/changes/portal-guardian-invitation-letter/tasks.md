# Tasks: the school can invite a guardian by letter

- [x] 1.1 `GuardianPortalInvitation::invite()` takes a channel; on `letter` it dispatches portaliq's invitation event with that channel and answers `invitation: code` with the code and its expiry; an unknown channel is refused; a portaliq without the code answers `unavailable`. Verify: PHPUnit `GuardianPortalInvitationTest`.
- [x] 1.2 The invite endpoint passes `channel` on and answers the code to the administration. Verify: PHPUnit `PortalGuardianControllerTest::testAnAdministrationManagerInvites`.
- [x] 1.3 `occ learniq:portal:invite-guardian --letter` prints the code. Verify: live on a test instance.
- [x] 1.4 Live with portaliq's change checked out: the command prints a code, the guardian signs in without an address, types it and sees her child. Verify: live on a test instance.

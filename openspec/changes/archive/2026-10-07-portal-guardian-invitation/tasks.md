# Tasks: invite a guardian to the parent portal

- [x] 1.1 `GuardianPortalInvitation`: provision a pending account for a verified email and write the `guardianRef` claim through portaliq's typed events; refuse a non-guardian profile, a bad address, a missing organisation; answer `portal-unavailable` without portaliq. Verify: PHPUnit `GuardianPortalInvitationTest`.
- [x] 1.2 `POST /api/portal/guardians/{guardianRef}/invite`, admin and administration-managers only. Verify: PHPUnit `PortalGuardianControllerTest`.
- [x] 1.3 `occ learniq:portal:invite-guardian`. Verify: live on a clean instance.
- [x] 1.4 Live: a guardian of the po example set is invited, signs in with DigiD at substantial and sees their own child and nobody else's. Verify: `tests/e2e/po-parent-flows.spec.ts`.

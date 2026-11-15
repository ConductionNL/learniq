# Proposal: invite a guardian to the parent portal

## Why

Found testing a primary school end to end on a clean install (2026-09-30). A guardian who signs in to the parent portal sees no child, no grades and no attendance.

Every collection in learniq's `parent` contribution is scoped by the claim `learniq.guardianRef` on the guardian's portal account (`PortalContributionProvider`, `scopeClaim: guardianRef`). Portaliq only writes a claim when the app that owns it asks, through `PortalAccountClaimRequestedEvent` (portaliq portal-identity-space REQ-PIS-003). Learniq never asked, so no guardian account ever carried the claim and the portal answered every parent collection with zero rows. There was also no way for a school to give a guardian access at all.

## What changes

- `GuardianPortalInvitation` provisions a pending portal account for the guardian's verified email address (portaliq REQ-PIS-001), then writes `claims.learniq.guardianRef` = the guardian's LearnerProfile uuid. On the first sign-in portaliq matches the account by that verified address (REQ-PIS-002), so the guardian lands on their own children.
- The school's administration (groups `admin` and `administration-managers`) invites through `POST /apps/learniq/api/portal/guardians/{guardianRef}/invite` with `{email, organisation}`, or `occ learniq:portal:invite-guardian <guardianRef> <email> <organisation>`.
- Without portaliq the invitation answers `portal-unavailable` and dispatches nothing. Learniq keeps no dependency on portaliq: the events are named by class string.

## Not in this change

- A button in the learner detail page. The API and the occ command are the entry points; a UI is a follow-up.
- Sending the guardian an email. The school tells the guardian to sign in with DigiD; the verified address is what links the account.

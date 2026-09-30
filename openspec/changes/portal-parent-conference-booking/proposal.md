# Proposal: a guardian books a parent-teacher conversation in the parent portal

## Why

Found testing a primary school end to end on a clean install (2026-09-30), flow "parent conference booking": a teacher opens a parent evening round, the guardian books in the portal, the teacher sees the booking and records the conversation. Four things stopped it:

1. **The parent portal had no conference surface.** The `parent` contribution offered no round, booking or time. Booking existed only as `BookConferenceSlotsView` inside Nextcloud, for guardians with a Nextcloud account (`parentIds`), which a school's guardians do not have.
2. **Nobody computed who is invited.** The spec says `send-invitations` computes `invitedLearnerIds` from the round's cohorts; nothing did, so the invitation notification had no recipients.
3. **A portal booking could not pass the submit guard.** `ConferenceSignupGuardianGuard` needs a signed-in user; a portal write has none. `learnerId`, `requestedTeacherIds` and `tenant_id` were required, and a portal form cannot know them.
4. **A teacher could not record the conversation report.** `ConferenceReport` allowed only `compliance-officers` and `team-leads`; the group teacher (`instructors`) got a 403 on create.

While in the parent contribution: the portal showed raw property names, uuids and `[object Object]` as table columns, and a guardian typed a child's uuid into the absence form.

## What changes

- `ConferenceRound` gains `invitedLearnerRefs`; the new lifecycle action `ConferenceInvitationAction` on `send-invitations` fills `invitedLearnerIds` and `invitedLearnerRefs` from the cohorts.
- The `parent` contribution gains three collections (`parentConferenceRounds` in `booking-open` for one of the guardian's children, `parentConferenceSignups`, `parentConferenceSlots`) and the action `createConferenceSignup` (round, child, note). The child is picked from the guardian's own children and portaliq refuses any other child (`crossRefs`).
- `ConferenceSignupPortalStamp` checks a portal booking (the child lists the guardian, the round is open and invited the child), stamps `learnerId`, `guardianId`, `tenant_id` and `submitted`, and requests the child's group teacher when the guardian names none. `ConferenceSignup.required` keeps only `conferenceRoundId`; the listener and the guard enforce the rest.
- `ConferenceReport` authorization lets `instructors` create a report, and read and update only their own (`teacherId` is their user id).
- The absence form gets a child picker, labels and the same cross reference. Every parent collection declares readable `columns`.
- The contribution declares `guardianAudience` (children from `parentChildren`, school from `schoolId`, groups from the new `parentGroupMemberships`) so portaliq's news, which matches items to a guardian's school and groups, reaches the school's guardians (portaliq `feat/news-audience-from-the-school-app`).

## Depends on

- portaliq `fix/claim-scoped-create-stamps-the-claim` (a create stamps the scope claim) and `fix/via-read-scoped-query` (a via read queries the subject's own rows and honours `filter`). Without them the booking is stamped with the wrong reference and the round list ignores `booking-open`.

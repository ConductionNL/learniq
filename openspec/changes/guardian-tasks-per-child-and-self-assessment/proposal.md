---
kind: spec
depends_on: [board-data-the-schemas-lacked, direct-conference-booking, site-guardian-portal-design]
---

# Proposal: guardian-tasks-per-child-and-self-assessment

## Why

Round 5 left three board items open:

- **Wilgenboom, the guardian's overview.** The board's task reads "Kies een tijd voor het oudergesprek van Sami". A conference round names its invited pupils in a list (`invitedLearnerRefs`), and portaliq neither turns a list into rows nor keys a lookup on one. So the task named the round and never the child.
- **Esdoornveen, "Nu invullen".** On her placement's "Werkprocessen" a student fills in her own estimate. There was no action to do it.
- **Esdoornveen, "Volgende stap".** The card's button "Zelfbeoordeling afmaken" had no page to open.

## What changes

The schema change is additive only: one new schema. Register 0.40.0 goes to 0.41.0, and the register entity 0.23.0 to 0.24.0.

- **New schema `conference-invitation`** (0.1.0). It holds one row per invited child per conference round: the round, the child (`learnerRef`), copies of the round's name, last booking day and booking mode, and a `status`:
  - `open` while the round is `booking-open` and the child has no time;
  - `booked` once the child has a time (a slot of the round naming the child in `booked`, `acknowledged`, `proposed`, `confirmed` or `completed`);
  - `closed` otherwise, and for a child no longer invited.

  A row is only created when it would be open or booked. The round itself is never changed.
- **`ConferenceInvitations`** writes the rows, and only the new or changed ones. **`ConferenceInvitationSync`** runs it inline after a round changes (created, or a change to its invited pupils, state, name, last day, mode or tenant) and after a child's time changes state. So the overview reads the booking right after it is made. **`BackfillConferenceInvitations`** (repair step) syncs every round that is open for booking.
- **Seeds:** the po generator writes the invitations of both open rounds. That is 59 rows: Vera's is booked through her acknowledged time, Sami's is open. `conference-invitation` is appended to the po schema order, so no existing uuid moves.
- **Guardian:**
  - `parentConferenceInvitations` reads the open invitations through the same child join as every parent read, so a guardian only ever sees her own children.
  - The overview's tasks block reads it. It uses the child's first name through the `childName` lookup on the row's `learnerRef` (portaliq lookup-by-row-field), and the title sentence "Pick a time for the parent-teacher conversation of {childName}" ("Kies een tijd voor het oudergesprek van {childName}"). The title falls back to the round's name.
  - The task opens `parentPickATime`, a page outside the menu. It shows the invitation and the two booking forms: a free time, and a preference. Booking itself is unchanged.
  - `titleTemplate` is a visible string, so the label translator now translates it.
- **Student:**
  - `fillInSelfAssessment` ("Nu invullen") is an update action on `werkproces-progress`. It is a row action on `studentWorkProcesses`. Portaliq re-reads the row under her own `learnerRef` before it writes, and writes only `selfAssessment`. The hours and every other field stay as they are, and the trainer's judgement lives in `werkproces-assessment`, which the action never reaches. The choices are the schema's enum: Good, Sufficient, Insufficient.
  - `studentSelfAssessment` is a record page on her placement, outside the menu. It lists her work processes with the hours and her estimate, each with "Nu invullen". No board draws this page, so it is the minimum.
  - The "Volgende stap" highlight gets `buttonLabel` "Finish your self-assessment" ("Zelfbeoordeling afmaken"), `page: studentSelfAssessment` and `withRecord: true`.
- **Test warning:** `NotificationRecipientGroupsAreDeclaredTest` compared a read rule (an array) as a group name, which raised a PHP warning in the full suite. It now compares only the group names.

## Not in this change

- A server-side guard on `werkproces-progress` for portal writes. Portaliq's field whitelist and its ownership re-read already hold the line, and no other portal action writes this schema.
- A board for the self-assessment page.

# Proposal: a parent books a free time, the teacher acknowledges it

## Why

Ruben (2026-10-02): "a parent should book from the portal, teacher acknowledges". In a primary school a parent evening is one teacher per group. Asking parents for a preference and letting the school plan the times afterwards (the flow `portal-parent-conference-booking` built) costs the school a planning step and leaves the parent waiting for a time. Direct booking removes both: the teacher publishes free times, the parent picks one, the teacher acknowledges it.

The preference flow stays. Secondary schools need it, because a parent there sees several subject teachers in one evening.

## What changes

- `ConferenceRound` gains `bookingMode` (`direct` or `preference`) and `maxBookingsPerChild`. A new round in a primary school (segment `po`) gets `direct`; elsewhere it gets `preference`. A round stored before this change has no value and keeps the preference flow.
- `ConferenceRound` gains the transition `create-free-slots` (`booking-open` to `booking-open`). On `open-booking` and on `create-free-slots`, a direct round cuts each submitted teacher availability into free slots of the round's length and buffer (`ConferenceFreeSlotGenerator`).
- `ConferenceSlot` gains the states `free`, `booked`, `acknowledged` and `declined`, the transitions `book`, `acknowledge` and `decline` (with a required `declineNote`), and the fields `teacherName`, `slotLabel`, `eligibleLearnerRefs`, `guardianRef`, `declineNote`, `bookedAt` and `acknowledgedAt`. `learnerId` is no longer required: a free slot has no learner.
- A portal booking is a `ConferenceSignup` create naming the child and the slot. `ConferenceSlotBookingStamp` checks the child is the guardian's and the round is open, and `ConferenceSlotClaim` takes the slot under two Nextcloud locks (the slot, and the child in the round). Two families never get the same time, and a child gets one time per round unless the round allows more.
- Only the slot's teacher, or a coordinator, team lead, administration manager or admin, acknowledges or declines (`ConferenceSlotTeacherGuard`).
- `ConferenceSlotBookingSync` keeps the booking in step with its slot, refuses a portal cancel after the booking window, and offers a cancelled or declined time again as a new free slot.
- The parent portal gains `parentConferenceFreeSlots`, the action `bookConferenceSlot` and the row action `cancelConferenceTime`. The preference action is renamed "Ask for a parent-teacher conversation".
- The round page lists "Bookings to answer". The po example set's group 7 parent evening books directly, with ten free times.

## Not in this change

- **Telling the guardian by e-mail.** Portaliq's change rule only notifies a resident whose own subject reference the record holds. A learniq booking holds the guardian's learniq reference (a claim), so the rule cannot reach the guardian. The guardian sees the status in the portal. A follow-up in portaliq (change rules on claim-scoped collections) makes the e-mail possible.

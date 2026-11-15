# Proposal: conference-answer-notice

## Why

Ruben asked for the rough patches in the primary-school parent portal to be fixed (2026-10-02). A guardian books a free conference time in the portal (direct-conference-booking, #1614). When the teacher acknowledges or declines it, the guardian gets no message: the booking only changes status on a page they have to revisit.

Portaliq's change rule reached only the resident whose portal reference is on the record. A booking holds the guardian's learniq reference (`guardianRef`). Portaliq change `claim-addressed-change-notices` lets a rule name its recipients by a claim; this change declares that rule.

## What changes

- The parent contribution's `notifications` holds one change rule, `conference.answered`, on `parentConferenceSignups`, field `lifecycle`:
  - `recipients`: the portal accounts whose learniq claim `guardianRef` the booking holds. Portaliq also reads the booking as each of them through the collection, so only a guardian who may see the booking hears about it.
  - `messages` for `acknowledged` ("De leerkracht heeft uw gesprekstijd bevestigd: <datum tijd>, met <naam leerkracht>.") and `declined` (with the teacher's note), in Dutch and English. Every other move, such as the parent's own cancel, is not reported.
- Nothing else in the manifest changes. No schema or register change: the booking already carries `guardianRef`, `startsAt`, `teacherName` and `declineNote`, and the booking follows the slot (`ConferenceSlotBookingSync`).

## Depends on

ConductionNL/portaliq change `claim-addressed-change-notices`. Without it, portaliq drops the rule (a rule on a `via` collection) and logs a warning; nothing breaks.

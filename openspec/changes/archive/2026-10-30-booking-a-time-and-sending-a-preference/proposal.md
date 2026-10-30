---
kind: code
depends_on: [absence-and-booking-fields-are-required]
---

# Proposal: booking-a-time-and-sending-a-preference

## Why

Ruben decided on 3 October 2026 that a guardian books a conversation in two separate forms. "Kies een tijd" is a direct booking: the time is required and the form offers the free times. "Stuur uw voorkeur" is a preference request: it has no time field at all, and the school plans the time. No field may carry a misleading "(niet verplicht)".

Both forms write a `ConferenceSignup`. The schema cannot require `slotId`, because a preference has none, and it cannot require `conferenceRoundId`, because a direct booking sends only the time. Portaliq now lets an action name its own required fields (`requiredFields`, portaliq#1139). It marks them required and refuses an empty one before the write.

## What changes

- `bookConferenceSlot` is labelled "Kies een tijd" and declares `requiredFields: [learnerRef, slotId]`.
- `createConferenceSignup` is labelled "Stuur uw voorkeur", declares `requiredFields: [conferenceRoundId, learnerRef]`, has no time field and sends with "Voorkeur versturen".
- The guardian's per-child page "Oudergesprekken" shows both forms, each titled by its label, then that child's times and requests. The server already refuses the wrong form for a round: a booking without a time in a direct-booking round (`signup-round-direct`), and a time in a preference round (`ConferenceSlotBookingStamp`).

## Layout chosen

`Main.dc.html` has one quick-action tile, "Oudergesprek boeken". It opens the child's "Oudergesprekken" page with both forms, direct booking first. Two pages would make a guardian guess which kind of round her school runs.

## Not changed

- The schema: `slotId` and `conferenceRoundId` stay optional on `ConferenceSignup`.
- The staff booking view and the pupil's actions.

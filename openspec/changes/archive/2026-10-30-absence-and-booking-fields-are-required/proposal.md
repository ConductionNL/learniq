---
kind: code
depends_on: []
---

# Proposal: absence-and-booking-fields-are-required

## Why

Ruben decided on 3 October 2026: the child on an absence report and on a conference booking is required in learniq. This reverses "leave the child as it is" of 2 October.

The trigger is portaliq#1130 (site forms, wave 1). Portaliq honours a form field's `required: true` only when the schema itself requires the field (WMEBV data minimisation, `ActionConfigNormaliser::applyFieldFlags`). With the new form markup every field the schema does not require reads "<label> (niet verplicht)". So "Kind" read "Kind (niet verplicht)" on the absence form and on both conference forms, although a report or booking without a child is meaningless.

## What changes

- `ExcuseRequest.required` gains `learnerRef` (schema 0.4.0 to 0.4.1). The field is no longer nullable.
- `ConferenceSignup.required` becomes `[learnerRef]` (schema 0.2.0 to 0.2.1). The field is no longer nullable.
- Register 0.34.35 to 0.34.36.
- The staff booking view (`BookConferenceSlotsView`) sends the pupil's learner profile as `learnerRef`. It sent only `learnerId`, and a listener cannot fill a required field: OpenRegister checks `required` in `ObjectService::saveObject` before the object reaches `MagicMapper`, where `ObjectCreatingEvent` is dispatched.
- `BackfillRequiredLearnerRefs` writes `learnerRef` on stored absence reports and conference signups that lack it, before `BackfillExcuseRequestTeachers`. OpenRegister validates the whole object on every update, so such a row would otherwise refuse every later write.
- The live e2e (`tests/e2e/po-parent-flows.spec.ts`) follows portaliq#1130's markup: a date is three boxes in a group named by the label.

## Every create path, checked

| Path | `learnerRef` before validation |
|---|---|
| Pupil's own absence report (portal `createExcuseRequest`, student) | yes: portaliq's `PortalObjectWriter::createObject` writes the scope field `learnerRef` from the pupil's claim into the body before `saveObject` |
| Guardian's absence report (portal `createExcuseRequest`, parent) | yes: a listed, required field, checked against her children |
| Staff absence form (`SubmitExcuseView`) | yes: `learnerRef: objectId(learner)`, the picked learner profile |
| Guardian's direct booking (`bookConferenceSlot`) | yes: a listed, required field |
| Guardian's preference request (`createConferenceSignup`) | yes: a listed, required field |
| Staff booking (`BookConferenceSlotsView`) | no: fixed in this change |
| Example sets (po, vo, mbo, he, training) | yes: 90 absence reports, every one with `learnerRef`; no seeded signups |
| Demo register (`learniq_mock_register.json`) | yes: 3 reports and 3 signups, all with `learnerRef` |
| Listeners that save signups (`ConferenceScheduleGenerator`, `ConferenceSlotBookingSync`) | they update stored signups; the backfill gives old ones their `learnerRef` |

## Not in this change: `slotId` and `conferenceRoundId` on ConferenceSignup

Ruben's decision named these too. Requiring them breaks shipped flows, so they stay optional until he chooses:

- `slotId`: a preference request (`createConferenceSignup`, and the staff booking view) has no time yet; the school plans it later (`ConferenceScheduleGenerator`). A waitlisted signup never gets one. Required, every preference request is refused.
- `conferenceRoundId`: the guardian's direct booking sends the time, not the round. `ConferenceSlotBookingStamp` fills the round from the slot, but only after validation. Required, every direct booking is refused.

Options for Ruben are listed in the PR. Until then the booking form reads "Tijd (niet verplicht)", and the request form reads "Oudergespreksronde (niet verplicht)".

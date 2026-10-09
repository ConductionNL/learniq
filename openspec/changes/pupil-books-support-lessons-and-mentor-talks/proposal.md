---
kind: spec
depends_on: [site-pupil-portal-design, portal-message-contacts]
---

# Proposal: pupil-books-support-lessons-and-mentor-talks

## Why

Two things a Vaartveld pupil does herself on the boards (8 October 2026) have no portal path:

- **A support lesson with places.** [vaartveld/Detail](https://identity.conduction.nl/screens/board?id=vaartveld/Detail) ends with "Steunles wiskunde. Elke dinsdag het 8e uur, van 15.20 tot 16.10 uur in lokaal 2.14. Meneer Demir legt uit wat je lastig vindt. Voor morgen zijn er nog 6 plekken." and the button "Inschrijven voor dinsdag 6 oktober". The teacher's side ([vaartveld/LqDetail](https://identity.conduction.nl/screens/board?id=vaartveld/LqDetail)) shows "Steunles: Wiskunde, vanaf di 6 okt". learniq has the record: an `elective-offer` with a window and a capacity, and `elective-sign-up` (`enrolment` spec, "A school offers optional lessons with a window and a capacity"). It is staff only; the student audience reads none of it.
- **The mentor talk.** [vaartveld/Berichten](https://identity.conduction.nl/screens/board?id=vaartveld/Berichten) draws "Kies een tijd voor het mentorgesprek" from mevrouw Kramer with time slots inside the message, and "Je vader krijgt ook een bericht". [vaartveld/LqDetail](https://identity.conduction.nl/screens/board?id=vaartveld/LqDetail): "Noor koos vanochtend een tijd. Haar vader komt mee." Booking a conference time is open to guardians only (`bookConferenceSlot` in the parent audience); the conference spec allows a self-signup for an 18+ learner, not for a pupil of sixteen with her parent.

Per deviation D-5 of the portal plan the slot picker does not live inside the message: the message links to the booking page. Lane T's gap list (8 October) names both items.

## What changes

- **Student audience, support lessons**: a collection `studentElectiveOffers` of the open `elective-offer` rows of the pupil's own school and year, with the next date, place, teacher name and `placesLeft` as a line ("Nog 6 plekken"), and an action `signUpForElective` that writes an `elective-sign-up` for the signed-in pupil only, through the rules the enrolment spec already sets (window, capacity, one sign-up per offer).
- **Student audience, mentor talks**: the conference round gains `pupilMayBook` (boolean, default false). When true, the student audience reads the free times of her own mentor's round (`studentConferenceTimes`) and may book one (`bookConferenceSlotAsPupil`), stamped to herself. Her guardians receive the same booking notice the teacher's acknowledgement sends today, and their portal shows the booked time.
- **The message links the booking**: the round's invitation notice to a pupil carries a link "Kies een tijd" to the booking page (D-5), not slots.

## Not in this change

- A pupil cancelling a talk her parent booked: one booking per pupil per round, whoever booked it; only the one who booked may cancel.
- Waiting lists on support lessons: `elective-offer` has none.

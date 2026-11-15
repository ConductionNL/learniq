---
kind: code
depends_on: [employer-portal-audience, portal-certificates]
---

# Proposal: participant-portal

## Why

The Warmtepompacademie's phone boards are drawn for a participant (school portal plan, wave 2, W2-6): Tom Verbeek opens "Uw volgende cursusdag · over 3 dagen", F-gassen: herhaling en examen on Thursday 8 October, when, where and with whom, his F-gassen certificate that runs out in eight weeks, and what comes after. A participant is an adult whose employer booked him; the pupil's pages (homework, grades, absence, timetable) are not his, and lane W2-B owns those.

## What changes

- **The `participant` audience** (`ParticipantSitePages`), scoped by the `learnerRef` claim a learner's portal account already carries:
  - `participantComingDays`: his enrolments still to come (`upcoming`), the first first;
  - `participantCourseDays`: all his enrolments;
  - `participantCertificates`: his issued certificates on `learnerId`, with the copies and the expiry line of `portal-certificates`.
- **Pages**: the overview (greeting, the next course day as a highlight with days, time and place, his certificates as dated rows with the expiry pill and words, and his later course days), "Mijn cursusdagen" with the detail of a day (trainer included), "Mijn certificaten".
- **The course day on the enrolment.** `EmployerBookingProjection` copies the booking's `firstDay`, `dayLabel`, `timeLabel`, `placeLabel`, `trainerName` and `upcoming` onto each participant's enrolment, so his reads join nothing. Enrolment 0.3.4, register 0.38.0. The training set seeds them.

## Decisions

- **An audience of his own** rather than the pupil's: the shared student pages would show him empty homework, grades and absence blocks, and they belong to another lane.
- **Sign-in (deviation D-6):** the participant signs in with the `nextcloud` mode on the account his invitation created, until a magic link exists. The e2e grants Tom a `participant` portal account with his `learnerRef`.
- **Reads only.** He cannot change a booking; "Kunt u toch niet? Bel de planning" stays text on the site.

## Not in this change

- What to bring ("legitimatie, certificaat, werkschoenen") and the day's programme: no field holds them yet; the course description carries them on the public page.
- "Over 3 dagen": the highlight shows the date; a relative day line would go stale in a stored copy.
- Messages ("Tot donderdag: dit moet u weten"): lane W2-B and portaliq's messages.
- An occ command to invite a participant: the claim lane's invitation link (D-6).

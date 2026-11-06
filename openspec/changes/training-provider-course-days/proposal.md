---
kind: spec
depends_on: [board-data-the-schemas-lacked, participant-portal, week-timetable-grid]
---

# Proposal: training-provider-course-days

## Why

The Warmtepompacademie's workplace boards (8 October 2026) run on the course day, with a minimum, a maximum and a decision whether the day goes ahead. learniq has courses and cohorts (a cohort is the course date, `board-data-the-schemas-lacked` gave it a capacity), but no minimum, no go/no-go, no status a board can group on and no attendance per part of the day:

- [warmtepompacademie/LqVandaag](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqVandaag), the planner: "Hybride warmtepomp op 22 oktober heeft 4 deelnemers. Het minimum is 6. Beslis uiterlijk donderdag 8 oktober of de dag doorgaat. Op 26 november zijn nog 9 plekken vrij." with "Deelnemers verplaatsen"; "5 inschrijvingen om te bevestigen"; "Bezetting tot 23 oktober"; "Trainers en lokalen".
- [warmtepompacademie/LqBord](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqBord): "Cursusdagen. Een dag schuift vanzelf door als het minimum is gehaald of de dag voorbij is. Slepen kan ook." with tabs Bord and Kalender.
- [warmtepompacademie/LqRolB](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqRolB), the trainer: "De middag is begonnen. Vul de aanwezigheid in. Vanochtend waren er 8 van de 9. Mehmet Kaya kwam om 10.15 uur binnen."; the group with columns Ochtend, Middag, Praktijk: opdracht 1; "Praktijkbeoordelingen"; "Klaarzetten voor donderdag".
- [warmtepompacademie/LqLijst](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqLijst): participants with course, course day, status and "Certificaat geldig tot", filters course and employer; the analysis asks for a colour rule on a date that nears or has passed.
- [warmtepompacademie/LqDetail](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqDetail): the participant with the enrolment's steps in the header and "Wat nu? Niets, tot donderdag", the next course day "Cursusdag openen (11 van 12)".
- [warmtepompacademie/MobielDetail](https://identity.conduction.nl/screens/board?id=warmtepompacademie/MobielDetail): "Meenemen: geldig legitimatiebewijs, uw huidige F-gassencertificaat, werkschoenen en werkkleding" (left out of `participant-portal`: "no field holds them yet").

The analysis board ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) marks the planner, trainer and administration roles "Nog uitzoeken" and the course days board "Uitbreiden". Lane T2's gap list names the auto-advance, the occupancy and attendance per part of the day.

## What changes

- **A course day's status**: `cohort` gains `minParticipants`, `goDecisionBy` (date), and `dayStatus` derived from bookings, enrolments, the decision and the date: `open`, `goes-ahead` (minimum reached or decided), `full`, `rounding-off` (the day is past, results or certificates open), `done`, `cancelled`. It moves by itself; a manual move on the board is logged and lasts until the next change.
- **The go/no-go decision**: an action on a course day "Gaat door" or "Gaat niet door", with "Deelnemers verplaatsen" moving the bookings of a cancelled day to another day of the same course with places.
- **The planner's Today**: First today rules (a day under its minimum with a decision due within 3 working days; enrolments to confirm), the week strip of `week-timetable-grid`, occupancy until a date, trainers and rooms.
- **Attendance per part of the day**: a session of a course day may have parts (`morning`, `afternoon`); attendance is registered per part, with an arrival time when late. The trainer's Today shows the group with a column per part and per practical assignment.
- **What to bring**: `course.bringList[]` (short lines), shown to a participant on her next course day and on the public course page.
- **Participants list and page**: columns course, course day, status, certificate valid until with the colour rule (warning within 8 weeks, error when passed); the page header with the enrolment's steps and a "Wat nu?" line.

## Not in this change

- Exam registration with the examining body and issuing certificates: `exam-registration-and-certificate-issue`.
- A checklist the participant ticks off per item: the boards show a list to read; ticking is not needed for a first version ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig): "Niet noodzakelijk voor een eerste versie").

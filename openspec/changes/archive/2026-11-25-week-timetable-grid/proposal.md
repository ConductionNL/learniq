---
kind: spec
depends_on: []
---

# Proposal: week-timetable-grid

## Why

Two boards draw a week as columns of days, and learniq has no page for it:

- [vaartveld/LqBord](https://identity.conduction.nl/screens/board?id=vaartveld/LqBord): "Weekrooster. Week 41, maandag 5 tot en met vrijdag 9 oktober, 20 lessen en 1 overleg", tabs "Mijn rooster" and "Mentorklas H4b", today marked, "Klik op een les voor de aanwezigheid, het huiswerk en de leerlingen. Wijzigingen komen van de roosterkamer en staan hier meteen." The analysis ([vaartveld/Nodig](https://identity.conduction.nl/screens/board?id=vaartveld/Nodig)): "Nog niet bekend. Er is geen bouwsteen voor een week in dagkolommen."
- [warmtepompacademie/LqVandaag](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqVandaag): "Cursusdagen deze week", five day columns with the course days and an occupancy bar "9 van 12"; today "Geen cursusdag. De hal is vrij." The analysis ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) marks the week strip and the occupancy bar "Ontbreekt" in nextcloud-vue.

`personal-timetable` lists upcoming sessions; `timetabling` has today- and week-scoped index views as lists. Neither draws days as columns and lesson hours as rows.

## What changes

- **A week grid page** in learniq: Monday to Friday as columns, the school's lesson hours (the `hour-plan` of the school) as rows for a school, or start times for a training provider. A lesson sits in its hour with subject, group, room, and a pill for a change (other room, cancelled, substitute). A lesson opens its page with attendance, homework and pupils.
- **Two scopes**: "Mijn rooster" (the sessions the user teaches or covers) and, for a mentor, her mentor class's timetable.
- **Week navigation** (previous, this week, next) and "vandaag" marked.
- **A week strip on Today** for a training planner: the five days with each course day, its trainer and room, and occupancy as "{n} van {max}" with a bar; it reads `cohort.capacity` (`board-data-the-schemas-lacked`) and live bookings and enrolments.
- The grid is a library component (`CnWeekGrid` or what nextcloud-vue names it); this change specifies learniq's use and asks nextcloud-vue for the component in the same round.

## Not in this change

- Editing the timetable in the grid. Changes come from the roosterkamer (`timetabling`).
- A month view.

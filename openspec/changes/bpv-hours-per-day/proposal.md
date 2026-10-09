---
kind: spec
depends_on: [internship-hours, board-data-the-schemas-lacked]
---

# Proposal: bpv-hours-per-day

## Why

`internship-hours` made a week of BPV hours a record (`bpv-hour-week`) that a student submits and a praktijkopleider approves or returns. The Esdoornveen boards (8 October 2026) write and judge hours per day:

- [esdoornveen/MobielDetail](https://identity.conduction.nl/screens/board?id=esdoornveen/MobielDetail): "Uren schrijven, maandag 5 oktober, Bakker Techniek BV": begonnen om, gestopt om, pauze (geen, 30 min, 60 min), "Dat is vandaag 8 uur", "Wat heb je gedaan?", "Bij welk werkproces hoort dit? Je mag er meer kiezen.", "Foto toevoegen", "Versturen naar Petra Bakker" and "Bewaren en later versturen".
- [esdoornveen/MobielHome](https://identity.conduction.nl/screens/board?id=esdoornveen/MobielHome): the praktijkopleider approves selected days or returns them with a question.
- [esdoornveen/Berichten](https://identity.conduction.nl/screens/board?id=esdoornveen/Berichten): "Vraag over je uren van dinsdag 29 september. Je schreef 8 uur op dinsdag 29 september. Volgens mij ging je om 14.00 uur naar de tandarts. Wil je de uren aanpassen? Dan keur ik de hele week goed." with the returned day ("Teruggestuurd, 08.00 tot 16.30 uur, 8 uur geschreven") and "Uren aanpassen".
- [esdoornveen/MijnOverzicht](https://identity.conduction.nl/screens/board?id=esdoornveen/MijnOverzicht): "Je uren van vandaag zijn nog niet geschreven" with "Uren van vandaag schrijven".
- [esdoornveen/Detail](https://identity.conduction.nl/screens/board?id=esdoornveen/Detail): the work processes with "Jouw inschatting" and "Nu invullen" (left out of `board-data-the-schemas-lacked`: no update action).

Deviation D-7 of the portal plan, accepted direction: keep the week as the record that is approved, with day lines inside it; the company approves or returns the week, and a return names the day. This change specifies that. Lane T2's gap list (8 October) names it.

## What changes

- `bpv-hour-week` gains `days[]`: per day `date`, `startTime`, `endTime`, `breakMinutes`, `hours` (worked out), `description`, `werkprocesCodes[]`, `photoRef`, and `returnedQuestion` (set by the trainer when she returns the week about that day). The week's `hoursSubmitted` is the sum of its days.
- **Writing a day**: the student action `writeHourDay` adds or replaces one day line of the week that holds the date, creating the week as `draft` when absent; "Bewaren en later versturen" keeps the week `draft`; "Versturen" submits the week (the existing transition).
- **Returning names the day**: `approveHourWeek` with outcome `returned` requires a day and a question; the student receives a portal notice "Vraag over je uren van {day}" from the praktijkopleider, with the question and a link "Uren aanpassen" to that day's form (D-5: the link, not a form in the message).
- **Today's line**: the student overview shows "Je uren van vandaag zijn nog niet geschreven" on a placement workday without a day line.
- **Own estimate**: the student action `estimateWerkproces` sets `selfAssessment` on her own `werkproces-progress` row ("Nu invullen").

## Not in this change

- Approving single days: the trainer approves the week (D-7). The phone board's day selection becomes "Week goedkeuren" with the days listed.
- A photo shown to the school coach: stored on the week as a file; who may read it follows the week.

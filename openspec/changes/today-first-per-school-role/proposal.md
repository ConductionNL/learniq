---
kind: spec
depends_on: [simple-today-dashboard, leave-requests-decided-by-the-school, attendance-warns-before-the-threshold]
---

# Proposal: today-first-per-school-role

## Why

`simple-today-dashboard` gives the teaching roles one Today page: a greeting, a "First today" card when attendance flags are open, four counts and the week's lessons. It names what it leaves out: marking, signals per pupil, parent messages, the Docent/Mentor switch. The school boards (8 October 2026) draw a Today for six roles, each with its own first card and its own tiles:

| Board | Role | First today | Tiles and lists |
|---|---|---|---|
| [wilgenboom/LqVandaag](https://identity.conduction.nl/screens/board?id=wilgenboom/LqVandaag) | group teacher | "Het register van vandaag is nog niet ingevuld. Twee leerlingen zijn afgemeld door hun ouders." | the day of groep 7 with "Nu bezig", expected in class 26 of 28, waiting on you 3 (2 reports, 1 message), parent evening 19 of 28 chose a time with 2 bookings to confirm, parent messages, group news read by 11 of 28 families |
| [vaartveld/LqVandaag](https://identity.conduction.nl/screens/board?id=vaartveld/LqVandaag) | subject teacher and mentor | "De aanwezigheid van H5a in het 2e uur is nog niet ingevuld" | my lessons per hour with fill state, marking stack 31 in 2 assignments, reported absent 3 of 102, messages |
| [esdoornveen/LqVandaag](https://identity.conduction.nl/screens/board?id=esdoornveen/LqVandaag) | teacher, switch to BPV-begeleider | "Sem Visser is 14 uur afwezig zonder reden" | lessons, signals in my classes, present 37 of 40, to assess 28 in 3 assignments, messages from students |
| [vaartveld/LqRolB](https://identity.conduction.nl/screens/board?id=vaartveld/LqRolB) | mentor | "Yusuf Aydin miste 4 lesuren in twee weken" | signals in my class, mentor talks 16 of 27 planned with "Herinnering sturen", today in H4b, from parents and colleagues |
| [wilgenboom/LqRolB](https://identity.conduction.nl/screens/board?id=wilgenboom/LqRolB) | intern begeleider | "Het plan van Tess Bakker loopt vrijdag af zonder evaluatie" | signals from teachers, pupils with care, plans that end, conversations this week |
| [wilgenboom/LqRolC](https://identity.conduction.nl/screens/board?id=wilgenboom/LqRolC), [vaartveld/LqRolC](https://identity.conduction.nl/screens/board?id=vaartveld/LqRolC) | directeur, teamleider | "Twee verlofaanvragen wachten op uw besluit"; "3 leerlingen misten 16 lesuren of meer" | attendance per group with registers filled (7 of 8 at 9.05), per year present, without report, average, 3 or more fails, to report, lesson cancellations today, leave to decide, mentor talks 214 of 342 planned |

The analysis boards ([wilgenboom/Nodig](https://identity.conduction.nl/screens/board?id=wilgenboom/Nodig), [vaartveld/Nodig](https://identity.conduction.nl/screens/board?id=vaartveld/Nodig), [esdoornveen/Nodig](https://identity.conduction.nl/screens/board?id=esdoornveen/Nodig)) mark the teacher's Today "Deels" and the IB, directeur, mentor and teamleider dashboards "Nog uitzoeken". No spec covers them beyond the shared Today (lane T gap list).

## What changes

- **One First today card per role, from one ordered rule list** (design.md): each role has rules in a fixed order; the first rule that has something shows, with one sentence, one line of context and at most two buttons. No rule, no card.
- **Role tiles** declared per role in the simple structure profile, each a count that opens the list it counts (the rule of `simple-today-dashboard`), and lists of at most five rows with an "Alle ..." link.
- **New counts learniq can make today**: registers not yet filled for my lessons so far today; parent reports waiting in my register; submissions waiting for marking per assignment (from `submission` without a grade); a conference round's chosen and to-confirm counts; plans (`learning-plan`, `group-plan`) ending within seven days without a planned evaluation; registers filled per group at this moment; pupils per year with three or more insufficient final grades; lessons cancelled today.
- **The mentor view**: a teacher who is the mentor of a cohort (`cohort.mentorId`) gets a switch Docent / Mentor on Today. The mentor view narrows signals, talks and messages to the mentor class. The BPV-begeleider view of `bpv-coach-today-and-placement-board` uses the same switch.
- **Messages from parents** on Today: the newest three conversations of portaliq's staff message endpoints where the teacher is a participant, with "Nieuw bericht schrijven". learniq reads them; portaliq keeps them.
- **The head of the school** sees leave requests to decide (`leave-requests-decided-by-the-school`) and pupils nearing the limit (`attendance-warns-before-the-threshold`).

## Not in this change

- launchpad's "Mijn werkdag" row "Vandaag eerst" over all apps (wilgenboom, vaartveld, esdoornveen and warmtepompacademie `LpStart`): it needs an agreement between apps and belongs in launchpad. learniq can offer its First today as a dashboard widget once that agreement exists.
- "Gelezen door 11 van 28 gezinnen" on group news: read receipts are portaliq's news.
- The week grid: `week-timetable-grid`.

---
kind: spec
depends_on: [today-first-per-school-role, attendance-summary-per-school-year]
---

# Proposal: learner-list-and-page-follow-the-boards

## Why

The staff list of pupils and the pupil's page look the same on three school boards (8 October 2026), and differ from learniq's list and dossier tabs in what they put first:

- **The list** ([wilgenboom/LqLijst](https://identity.conduction.nl/screens/board?id=wilgenboom/LqLijst), [vaartveld/LqLijst](https://identity.conduction.nl/screens/board?id=vaartveld/LqLijst), [esdoornveen/LqLijst](https://identity.conduction.nl/screens/board?id=esdoornveen/LqLijst)) has columns per school kind: po "Vandaag" (In de klas, Ziek gemeld, Tandarts tot 11.30), "Afwezig dit jaar", "Te laat", "Oudergesprek 29 okt" ("18.10 uur, wacht op u"), "Zorg" ("Plan loopt", "In gesprek met IB"); vo "Signaal", "Aanwezig dit jaar", "Gemiddelde", "Mentorgesprek", sorted "leerlingen met een signaal eerst"; mbo "Leerbedrijf", "BPV-uren goedgekeurd", "Aanwezig", "Status". Saved views carry a count ("Afwezig vandaag", "Nog geen gesprekstijd", "Met zorg").
- **The page** ([wilgenboom/LqDetail](https://identity.conduction.nl/screens/board?id=wilgenboom/LqDetail), [vaartveld/LqDetail](https://identity.conduction.nl/screens/board?id=vaartveld/LqDetail), [esdoornveen/LqDetail](https://identity.conduction.nl/screens/board?id=esdoornveen/LqDetail)) opens with a header (name, state pill "In de klas" or "Wiskunde A onvoldoende" or "BPV loopt", group, age, pupil number, profile or crebo), then a "Wat nu?" card ("Oudergesprek op donderdag 29 oktober, 18.00 uur. De tijd is gekozen door Fatima Hulstkamp en door u bevestigd", with the steps to prepare and record it), results as bars with the change since the last report, attendance this year, the conversation with the parents on the page with a reply field, notes visible to the team only, and a side column with the people around the pupil (mentor, teamleider, zorg, praktijkopleider, studieloopbaan).

The analysis boards mark the list and the page "Deels". learniq's `LearnerProfileDetail` has tabs; the header figures, the next-step card and the parent conversation on the page are not specified anywhere.

## What changes

- **Today's state per pupil**: a read model field `todayState` on the learner (in class, reported ill, late, appointment until a time, not yet registered), worked out from today's attendance and open excuse requests; used by the po list and the page header.
- **List columns per school kind**, declared in the structure profile for the po, vo and mbo example sets: today, absence this year and late (from `attendance-summary`), conference time with its state, care (an active `learning-plan` or an open support request), signal (an open attendance flag or a warning from `attendance-warns-before-the-threshold`, or three or more insufficient grades), average, company and approved BPV hours, placement state.
- **Saved views with a count** on the list: absent today, no conference time yet, with care, with a signal.
- **The page header**: name, initials, a state pill (the most important open signal, else today's state), group, age, pupil number, and the profile or the qualification with its crebo.
- **"Wat nu?"**: one next step for this pupil, the first of: a booked conference in the coming 14 days, an assessment or visit in a placement, an open signal; with up to three preparatory lines and one action.
- **The parent conversation on the page**: the newest messages between the school and this pupil's guardians, read through portaliq's staff message endpoints, with a reply field.
- **People around the pupil** in the side column: mentor, teamleider, care coordinator (with "geen lopend plan" when none), support lesson, praktijkopleider, BPV-begeleider, studieloopbaan.

## Not in this change

- Results as bars with deltas are `report-card` data already; drawing them is the library's.
- Writing a care plan from the page.

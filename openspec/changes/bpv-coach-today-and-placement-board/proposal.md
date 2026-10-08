---
kind: spec
depends_on: [internship-hours, today-first-per-school-role]
---

# Proposal: bpv-coach-today-and-placement-board

## Why

Esdoornveen's workplace boards (8 October 2026) give the BPV-begeleider her own day and a board of placements per phase:

- [esdoornveen/LqRolB](https://identity.conduction.nl/screens/board?id=esdoornveen/LqRolB): "Mijn BPV-studenten", First today "Daan Smits (MT2A) heeft nog geen BPV-plek. De BPV-periode loopt vijf weken. Elke week zonder plek kost hem 24 uur. Twee leerbedrijven hebben nog een plek voor mechatronica." with "Bedrijven met een plek"; "Bedrijfsbezoeken in week 42" as a week strip ("Jesse Kok, Van Dijk Machinebouw: Nog geen datum voor de tussenbeoordeling. Laatste contact met het bedrijf: 9 september", "Datum voorstellen"); "Uren die wachten op een leerbedrijf, langst wachtend eerst" with "Herinneren"; "Studenten per fase" with "Naar het bord".
- [esdoornveen/LqBord](https://identity.conduction.nl/screens/board?id=esdoornveen/LqBord): "BPV-plekken per fase. 24 studenten van MT2A, BPV-periode tot en met 29 januari 2027. Een kaart schuift vanzelf door als de stap klaar is. Verslepen kan ook." The analysis ([esdoornveen/Nodig](https://identity.conduction.nl/screens/board?id=esdoornveen/Nodig)): the library has a board view, "te laat" in words on the card is missing.
- [esdoornveen/LqVandaag](https://identity.conduction.nl/screens/board?id=esdoornveen/LqVandaag): the switch "Docent / BPV-begeleider".

`bpv` holds placements, POK signing, assessments and visit reports. No record holds a planned visit (`site-workplace-trainer-portal-design`: "No record holds a planned visit"), placements have no phase a board can group on, and no screen gathers a coach's day. Lane T2's gap list names the "te laat" words and the visit week strip.

## What changes

- **A phase on a placement**: `bpv-placement.phase` worked out from the facts it already has: `searching` (no company), `agreement` (company, POK not fully signed), `running` (POK active, period started), `assessment` (an assessment due within 14 days or open), `rounding-off` (period ended, final assessment or hours not approved), `done`. The phase moves by itself when a fact changes ("schuift vanzelf door"); a manual move on the board is kept until the next fact change and logged.
- **Late in words**: `phaseDueAt` per phase (the agreement by the period start, the mid assessment by its date) and `lateLabel` ("2 weken te laat", "te laat sinds 1 oktober") as a calculation, shown on the card.
- **A planned visit**: new schema `bpv-visit` (placement, coach, `plannedAt`, `kind` (kennismaking, tussenbeoordeling, eindbeoordeling), lifecycle `to-plan`, `proposed`, `planned`, `done`), linked to the visit report once written. "Datum voorstellen" proposes a date to the praktijkopleider through her portal; she accepts it there.
- **The coach's Today** (the BPV-begeleider view of `today-first-per-school-role`): First today rules (a student without a company in a running BPV period; a visit due this week without a date; hours waiting more than seven days), the visit week strip, hours waiting per company oldest first with "Herinneren" (a notice to the praktijkopleider), students per phase with a link to the board, companies with a free place (from `leerbedrijf` capacity where the verification provider gives one).

## Not in this change

- Matching a student to a company. The board shows companies with a place; choosing is the coach's.

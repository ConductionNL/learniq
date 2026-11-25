---
kind: spec
depends_on: [today-first-per-school-role]
---

# Proposal: exam-committee-today-and-keuzedelen

## Why

Two Esdoornveen boards (8 October 2026) show mbo exam work learniq does not specify:

- [esdoornveen/LqRolC](https://identity.conduction.nl/screens/board?id=esdoornveen/LqRolC), the examencommissie: First today "Bezwaar tegen de uitslag van Engels spreken: beslissen voor vrijdag 9 oktober. Ingediend op 25 september door een student van MT3A. Het examenreglement geeft de commissie tien werkdagen. De reactie van de beoordelaar is binnen." with "Dossier lezen" and "Besluit voorbereiden"; "Resultaten vaststellen: 14 resultaten uit 3 afnames"; "Komende afnames"; "Volgende vergadering, donderdag 8 oktober, 6 agendapunten" (bezwaar, resultaten vaststellen (14), examenplannen cohort 2027 (2), vrijstellingen (3)).
- [esdoornveen/LqDetail](https://identity.conduction.nl/screens/board?id=esdoornveen/LqDetail), the student page: "Examens en keuzedelen: 3 van 11 examenonderdelen behaald. Volgende: NE-3F-CE op 3 november. Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober."

The `exam-board` spec holds exemption and fraud cases. It has no objection against a result (bezwaar tegen een beslissing van de examinator, WEB article 7.4.6 and the school's exam regulation), no act of establishing results per sitting, and no exam plan per cohort. learniq has no keuzedeel at all: `elective-offer` is an optional lesson, not a part of the qualification with its own exam (portal plan section 3.3: "keuzedeel 0 hits"). The analysis board ([esdoornveen/Nodig](https://identity.conduction.nl/screens/board?id=esdoornveen/Nodig)) marks the examencommissie "Nog uitzoeken" and asks whether the decision making belongs to decidiq.

## What changes

- **Objection against a result**: new schema `exam-objection` (the grade entry, the student, `submittedAt`, `decideBy` from the regulation's term in working days (default 10), the examiner's response, the decision with rationale, lifecycle `submitted`, `examiner-responded`, `decided`, `withdrawn`). A decision that changes the result writes a new grade entry through the existing publish path.
- **Establishing results**: an exam sitting's results get `establishedAt` and `establishedBy`; the committee establishes all results of a sitting at once; until then the student sees them as provisional.
- **The committee's Today**: First today rules (an objection past half its term; results waiting longer than five working days), "Resultaten vaststellen" counts per sitting, coming sittings, and the open items of the next meeting (objections, results, exemptions) as an agenda list. The meeting itself and its minutes stay out: decidiq handles meetings, and this change only lists what waits for the committee.
- **Exam progress per student**: `examProgressLabel` on the student ("3 van 11 examenonderdelen behaald") and the next sitting, from the exam plan of her programme.
- **Keuzedelen**: new schema `keuzedeel-offer` (code, name, SBU, the period, the choice window, places) and `keuzedeel-choice` (student, offer, lifecycle `chosen`, `approved`, `placed`); a student chooses inside the window from her portal; the page line reads "Nog niet gekozen, kan tot 16 oktober".

## Not in this change

- An exam plan editor: exam plans per cohort are read from the programme's existing structure; writing them is separate.
- Meetings and minutes: decidiq.

---
kind: spec
depends_on: [site-guardian-portal-design, attendance-roll-call]
---

# Proposal: absence-reports-seen-and-ended

## Why

The school boards (8 October 2026) draw three things around a parent's absence report that learniq does not hold:

- **Seen before approved.** [wilgenboom/MijnLijst](https://identity.conduction.nl/screens/board?id=wilgenboom/MijnLijst) and [wilgenboom/MijnOverzicht](https://identity.conduction.nl/screens/board?id=wilgenboom/MijnOverzicht) show Sami's report of this morning as "Gezien door juf Esra om 8.12 uur" before anyone approved it. The analysis board ([wilgenboom/Nodig](https://identity.conduction.nl/screens/board?id=wilgenboom/Nodig)) marks it "Deels": the form and the approval with name and time exist, the seen state does not. A parent wants to know the school read it; approving is a later, separate act.
- **Per lesson hour, and ending it.** [vaartveld/Nodig](https://identity.conduction.nl/screens/board?id=vaartveld/Nodig) asks for "Melden per dag of per lesuur, en beter melden", and what differs for a pupil of eighteen. `excuse-request` holds whole days (`dateFrom`, `dateTo`) and nothing ends an open illness.
- **The teacher's side.** [wilgenboom/LqVandaag](https://identity.conduction.nl/screens/board?id=wilgenboom/LqVandaag) and [wilgenboom/LqBord](https://identity.conduction.nl/screens/board?id=wilgenboom/LqBord) show the reports "already waiting for you" in the register; opening them is the moment they are seen.

Lane T's gap list (8 October) names the seen state as uncovered by any spec or open change.

## What changes

- `excuse-request` gains `seenBy` and `seenAt`, stamped the first time a teacher of the pupil's group opens the report or the register that holds it. Stamping is idempotent; a second teacher opening it changes nothing.
- The guardian's collection projects a line "Gezien door {teacher} om {time}" while the report is `submitted`, and the decision line once decided.
- `excuse-request` gains `fromLessonHour` and `toLessonHour` (optional): a report for part of a day names the hours, and only the attendance of those lessons follows the decision.
- `dateTo` may stay empty for an illness ("ziek, nog niet beter"); a new action `reportRecovered` sets the last day. Until then the report covers each following school day.
- A pupil of eighteen or older reports for herself through the student audience (`createExcuseRequest` exists there; this change adds the lesson hours and `reportRecovered` to it), and her guardians see her report only when she has not withdrawn their access.

## Not in this change

- Notifying the parent when the report is seen. The portal shows it; a push or mail is portaliq's notification preference.
- Changing who approves.

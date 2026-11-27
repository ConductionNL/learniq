---
kind: spec
depends_on: []
---

# Proposal: attendance-warns-before-the-threshold

## Why

learniq raises an `attendance-flag` when a pupil crosses the leerplicht threshold (16 lesuren unauthorised absence in four weeks). The boards want the count before that moment, so a teacher can act while it still helps:

- [esdoornveen/LqVandaag](https://identity.conduction.nl/screens/board?id=esdoornveen/LqVandaag): "Sem Visser (MT1B) is 14 uur afwezig zonder reden. Geteld over vier weken. Bij 16 uur moet de school een melding doen bij leerplicht. Hij is 17 jaar." with "Gesprek plannen".
- [wilgenboom/LqRolC](https://identity.conduction.nl/screens/board?id=wilgenboom/LqRolC): "Afwezig zonder toestemming. Bij 16 uur in vier weken moet de school dit melden bij de leerplichtambtenaar. learniq waarschuwt u voordat die grens is bereikt." with rows "Finn Weidewijk, 6 van 16 uur" and "Ryan Maas, 12 van 16 uur".
- [vaartveld/LqRolC](https://identity.conduction.nl/screens/board?id=vaartveld/LqRolC) counts the pupils past the line ("3 leerlingen misten 16 lesuren of meer"), which the flag already does.
- [esdoornveen/Nodig](https://identity.conduction.nl/screens/board?id=esdoornveen/Nodig): "De teller vooraf tonen (14 van 16 uur), zodat een docent kan ingrijpen voor de melding." marked "Deels".

## What changes

- **A rolling count per pupil**: `attendance-summary` gains `unauthorisedHoursWindow` (lesuren without permission in the threshold's window ending today), `thresholdHours` (the limit of the threshold that applies) and `windowLabel` ("6 van 16 uur"), kept fresh by the same recount that keeps the summary.
- **A warning level**: an `AttendanceThreshold` gains `warnAtHours` (default: three quarters of the limit). A pupil at or above it and under the limit is "bijna" and shows on the Today card and list of `today-first-per-school-role`; nothing is reported and no flag is created.
- The age line ("Hij is 17 jaar") comes from the learner profile; above eighteen the leerplicht threshold does not apply and the count reads against the school's own attendance rule, if any.

## Decisions

- The warning is a read model, not a flag. A flag is appendOnly evidence for a report; a warning disappears when the count drops, and must leave no trace that suggests a report.
- No notification is sent at the warning level; the boards show it on staff screens only.

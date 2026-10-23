---
kind: spec
depends_on: [site-guardian-portal-design]
---

# Proposal: leave-requests-decided-by-the-school

## Why

The directeur of De Wilgenboom and the teamleider of Vaartveld open their day on leave requests (screens 8 October 2026):

- [wilgenboom/LqRolC](https://identity.conduction.nl/screens/board?id=wilgenboom/LqRolC): "Twee verlofaanvragen wachten op uw besluit. De oudste is van woensdag. U beslist binnen vijf schooldagen, dus uiterlijk woensdag." Below it a list "Verlof te beslissen" with "Familie El Idrissi, Hamza, groep 7: Bruiloft in de familie, donderdag 29 en vrijdag 30 oktober. Aangevraagd op woensdag. Beslis uiterlijk woensdag 7 oktober" and the buttons Toestaan, Afwijzen, Aanvraag lezen.
- [vaartveld/LqRolC](https://identity.conduction.nl/screens/board?id=vaartveld/LqRolC): a tile "Verlofaanvragen, 2 nieuw, Beoordelen".

learniq has no record for this. `excuse-request` reports an absence after the fact (illness, a medical appointment) and a group teacher or coordinator approves it. A leave request (verlof buiten de schoolvakanties, Leerplichtwet 1969 article 11f and 14) is asked in advance, is decided by the head of the school up to ten school days, and above ten days by the leerplichtambtenaar. The decision is a formal one: the parent may object, so it records who decided, when and why. No learniq or portaliq spec or open change covers it (lane T gap list, 8 October).

## What changes

- **New schema `leave-request`**: the pupil (`learnerRef`), who asked (`requestedByRef`, the guardian), the days (`dateFrom`, `dateTo`), the kind (`wedding`, `funeral`, `religious-observance`, `family-circumstance`, `holiday-outside-school-holidays`, `other`), the reason in words, an optional attachment, `schoolDays` (the school days in the range, worked out on save), `decideBy` (the date by which the school must answer), the decision (`decidedBy`, `decidedAt`, `decisionNote`) and a lifecycle `submitted`, `allowed`, `refused`, `forwarded`, `withdrawn`.
- **Who decides**: up to ten school days the head of the school (role `administration-manager`, the directeur or teamleider of the pupil's department); above ten the request is `forwarded` to the leerplichtambtenaar and the school records the answer it gets.
- **Allowed leave becomes attendance**: when a request is allowed, the pupil's attendance on those days reads as absent with permission, as an approved excuse does today.
- **The guardian's portal** gets an action `requestLeave` on her own child (form with the days, the kind, the reason) and a collection of her requests with their state and the decision in words.
- **The decider's screen**: a list "Verlof te beslissen", oldest first, with the deadline in words, and an allow and a refuse action that ask for a note on a refusal. The count feeds the Today card of `today-first-per-school-role`.

## Decisions

- The deadline is a setting per school (default: five school days after the request), not law in code. The boards say "binnen vijf schooldagen".
- A refusal needs a note; an allow does not.
- The request is not an `excuse-request` kind. The two differ in who decides, when it is asked and what a refusal means, and a shared lifecycle would blur the formal decision.

## Not in this change

- Sending the forwarded request to the municipality through integriq. The school records the forward and the answer by hand; a data exchange can follow the pattern of the leerplicht report.
- An objection procedure against a refusal: the decision note names how to object; handling it is outside learniq.

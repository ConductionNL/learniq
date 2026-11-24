---
kind: code
depends_on: [example-portal-declares-its-site]
---

# Proposal: example-set-declares-its-events

## Why

The vaartveld Artikel board (8 October 2026) is a news item about an evening, "Informatieavond profielkeuze op dinsdag 3 november", with the facts and a sign-up card: a pupil of 3 havo or 3 vwo answers for herself and says how many seats she needs. Portaliq built the event side (`event-sign-up-by-a-pupil-with-seats`): a `schoolEvent` may let a learner answer (`rsvpBy`), ask seats, cap them with a `capacity` and close at a `signupDeadline`, and a `newsItem` names its event in `eventRef`. Its last task waits on learniq: "learniq's vo example portal publishes the evening with its event". The vo declaration had the news item and no event, and an example set could not declare events at all.

## What changes

- **A set may declare `events`** in `lib/Settings/portals/<set>.json`: portaliq `schoolEvent` objects as written there. `ExamplePortalContent::events()` writes each one that does not exist yet, matched by title, as `published`, before the news, and never writes over one that exists.
- **A news item may name its event by title (`event`).** The load writes the found event's id as `eventRef` and never writes the `event` key itself. An event that is not there leaves the item without a reference.
- **The dates move with the load week**, as the pages and news do: `dated()` shifts the declared events, and `moveDates()` moves the stored ones on a reload in another week.
- **The vo set declares the evening**: "Informatieavond profielkeuze, klas 3" on 3 November, 19.30 to 21.00, in the aula, for the school, answered by a guardian or the pupil herself, up to 3 seats an answer, 300 seats in all, answers until 30 October. The news item names it.
- The provisioner reports `events n created, n kept` beside menus, pages and news.

## Not in this change

- The live check on the vaartveld portal (portaliq `event-sign-up-by-a-pupil-with-seats` keeps it).

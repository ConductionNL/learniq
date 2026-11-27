---
kind: code
depends_on: [portal-message-contacts]
---

# Proposal: portal-public-index

## Why

The school websites (plan `PORTAL-PLAN.md` item W2-3, gaps G-12, G-15) show what a school offers to a visitor who has not signed in: the Warmtepompacademie's "Cursusaanbod" with each course's next date, days, place and start month; Esdoornveen's "Opleidingen" with level and learning path; Vaartveld and De Wilgenboom's news search that also finds the school's calendar. Portaliq's `portal-public-catalogue` asks every app for a public index through the optional provider method `getPublicIndex(string $portal)`. Learniq offers none, so these pages hold typed-in lists that go stale.

## What changes

- **`PortalPublicIndex`** (new, `lib/Portal/`): for one portal, the published courses that are offered (a training course, `level: corporate`, or one open to sign up; the school-year courses of po, vo and mbo are taught to groups, not offered) that still have a run to come (first run's first and last day, its number of days, "ook op" the other runs' first days, facets "Plaats" and "Start in"), the published programmes (facets "Niveau" and "Leerweg" read from the opening of the description, "Niveau 4, beroepsopleidende leerweg (bol)", the way the school writes it), and the school-wide days still to come from `school-event` (`audience: school`; a day for some groups only stays out). Never a person, a group's own day or anything a learner did.
- On an instance with several example sets, an example portal shows only its own set's objects, recognised by the fixed uuid namespace each set is generated in (`SET_NAMESPACE`, checked against every set's school uuid by a test); any other portal shows everything public.
- **`getPublicIndex($portal)`** on `PortalContributionProvider`, through an optional third constructor argument; `new` with no arguments answers nothing.
- **The example portals place the blocks** (shared file `lib/Settings/portals/*.json`, small): `nlCatalogue` on `/zoeken` (po, vo: news and calendar), `/opleidingen` (mbo: programmes) and `/cursusaanbod` (training: courses with date tiles); the academy's home "Eerstvolgende cursusdagen" fills itself (`source: {types: [course]}`). A page that already exists is not changed by a reload (the provisioner writes only what is missing).
- Dutch words for the kinds, facets and meta lines.

## Extended on 8 October: the editor boards

The editor boards place two blocks that read this index, and the index cannot answer either yet (lane T gap list, 8 October):

- [vaartveld/Editor](https://identity.conduction.nl/screens/board?id=vaartveld/Editor): a block "Toetsrooster" on a website page, with the options "Afdeling en leerjaar" (4 havo, 5 havo, 4 vwo, "De klas van de bezoeker"), "Toetsweek" (toetsweek 1, 9 to 13 November) and "Wat laat u zien?" (lokaal, wat je meeneemt, weging voor het schoolexamen); columns Dag, Tijd, Vak, Lokaal. "De toetsen komen uit learniq. Een toets wijzigen doet de roostermaker daar. Dit blok volgt vanzelf."
- [wilgenboom/Editor](https://identity.conduction.nl/screens/board?id=wilgenboom/Editor): a calendar block "Vakanties en vrije dagen" with "Wat laat het blok zien": vakanties, studiedagen en vrije dagen, activiteiten van school, ouderavonden; and "Hoeveel dagen vooruit".

Added to this change:

- **A test schedule in the index**: the published `exam-sitting` rows of an exam period that the school marked public, each with the day, start and end, the subject name (readable copy of the assessment's course), the room name, and the department and year of its cohorts; the period's name ("Toetsweek 1, 9 - 13 november"). Filters: department and year, exam period. Optional columns the block may show: room, what to bring (`exam-period.bringList`), weight for the school exam (the assessment's PTA weight).
- **A category on every school day**: the school-day items carry `category` from `school-event.kind` mapped to four words (vakantie, studiedag of vrije dag, activiteit, ouderavond), so a calendar block can show the categories an editor picks.

The blocks themselves are portaliq's `editor-blocks-read-public-app-data`.

## Not in this change

- A course or programme detail page; prices ("[PRIJS]", D-11); places left (no capacity on a cohort).
- Documents (PTA, letters, the school guide) in the search: portaliq's `portal-public-search`, after OpenRegister's `rbac-default-authenticated`.

## Impact

- `lib/Portal/PortalPublicIndex.php` (new), `PortalContributionProvider.php`, `lib/Settings/portals/{po,vo,mbo,training}.json`, `l10n/`.
- Stacked on `portal-message-contacts` (both add an optional constructor argument to the provider).

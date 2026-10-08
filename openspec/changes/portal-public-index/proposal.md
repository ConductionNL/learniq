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

## Not in this change

- A course or programme detail page; prices ("[PRIJS]", D-11); places left (no capacity on a cohort).
- Documents (PTA, letters, the school guide) in the search: portaliq's `portal-public-search`, after OpenRegister's `rbac-default-authenticated`.

## Impact

- `lib/Portal/PortalPublicIndex.php` (new), `PortalContributionProvider.php`, `lib/Settings/portals/{po,vo,mbo,training}.json`, `l10n/`.
- Stacked on `portal-message-contacts` (both add an optional constructor argument to the provider).

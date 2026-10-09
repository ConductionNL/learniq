---
kind: spec
depends_on: [school-portals-match-their-boards]
---

# Proposal: academy-public-pages-follow-the-boards

## Why

Proof run 3 (9 October) put the Warmtepompacademie's public pages beside their boards (Home, Zoeken, Contentpagina). Portaliq has merged the options these pages need (site-breadcrumb-follows-the-school-boards, site-catalogue-follows-the-school-boards, site-callouts-steps-and-tables-follow-the-boards, site-home-follows-the-school-boards), but the academy's declaration did not use them:

- the breadcrumb named a page by the header menu's word, where the boards use the page's own title;
- "U oefent op echte opstellingen" was a markdown block: no photo half, links stacked;
- the content page "Medewerkers inschrijven" had no heading "Dit hebben wij van elke deelnemer nodig", showed "Weet u nog niet wie er gaat?" as a melding where the board has a plain paragraph, folded the heading "Verplaatsen of annuleren" into the table caption, and put "Betalen" and "Overleggen met de planning" in the main column where the board puts them as cards beside it;
- the course catalogue had its search field above both columns and a kind label above each title, where the board puts the field in the filter column and the level as a fact under the summary;
- the board's buttons carry a chevron.

## What changes

Only `lib/Settings/portals/training.json`:

- `portal.breadcrumb: "page"`.
- Home: "U oefent op echte opstellingen" as a second hero (heading level 2) with the photo marked by `asideImage.label` and the two links as text links (`style: link`); "Medewerkers inschrijven" with a chevron.
- Medewerkers inschrijven: a level-2 heading over the three things a participant needs; "Weet u nog niet wie er gaat?" as a paragraph; "Verplaatsen of annuleren" as a level-2 heading with its own paragraph, the table caption hidden and its first column as row headers; "Betalen" and "Overleggen met de planning" as `nlAlert kind: plain` under the side list; the button with a chevron.
- Cursusaanbod: `nlCatalogue` with `cardStyle: meta` and `searchPlacement: rail`.

## Not in this change

- Three cards for Naam, Geboortedatum and E-mailadres: no portaliq block draws a description list as cards (request to portaliq).
- The photo on the left and the grey band of "U oefent op echte opstellingen": the hero puts its aside on the right on its own band (request to portaliq).
- The side link "Een leerling-monteur opleiden": the academy has no page for it yet.
- The Mijn academie card's "Bekijk en betaal facturen" and "Deelnemers loggen in met een link per e-mail": invoices (academy-invoices-on-the-portal) and the e-mail link sign-in are not live, so the card does not promise them yet.
- The nine courses, their prices, levels and "ook op" dates: example data and the public index (separate change).
- An existing portal only gets `breadcrumb` once `ExamplePortalProvisioner::FILLABLE` lists it (request to lane FIX-L).

---
kind: spec
depends_on: [example-portal-declares-its-site, school-portals-match-their-boards]
---

# Proposal: wilgenboom-public-pages-follow-the-boards

## Why

Proof run 3 (9 October) put the public pages of De Wilgenboom beside their boards (school-design `wilgenboom`: Home, Zoeken, Artikel, Contentpagina). Portaliq has since merged the options those boards need: search facets, the article's crumb and pill, callouts with a button, row headers, and the breadcrumb that ends on the page title. The po declaration did not use them yet.

## What changes

All in `lib/Settings/portals/po.json`:

- Portal: `breadcrumb: "page"` (portaliq `site-breadcrumb-follows-the-school-boards`).
- Home: the "Verlof aanvragen" tile draws a document; the agenda's "Ouderavond" is no link, as on the board.
- Zoeken: the filter column "Soort" and "Voor wie" (`kindFacet`, `audienceFacet`, "Voor wie" as radios), the field without a visible label (`labelHidden`, label "Zoekterm"), and the board's intro.
- Artikel: the crumb runs through "Nieuws en documenten" (`sectionHref`, `sectionLabel`), the pill "Nieuws" above the title (`kindLabel`), and the light card "Nieuws uit de groep van uw kind" at the top of the right column, above "Meer nieuws". The Kinderboekenweek item ends with the board's closing sentence.
- Contentpagina: the "Afwezig melden" button inside "Online melden" (`action`), "Liever bellen?" as a plain card with the number in bold, the last paragraph as a warning callout, and the table's first column as row headers.

## What an instance that loaded the set before sees

Nothing changes there: the provisioner never writes over a page or news item that exists. A fresh load (proof run 4) gets the new pages. `breadcrumb` is written once `ExamplePortalProvisioner::FILLABLE` lists it (request to lane FIX-L).

## Not in this change

- `breadcrumb` in `ExamplePortalProvisioner::FILLABLE` (shared code, lane FIX-L).
- Newsletters as their own kind ("Nieuwsbrieven (2)"), the "Schooljaar" facet and a link inside a table cell ("Verlof aanvragen"): portaliq has no field or option for them yet.
- The side links "Gym en zwemmen" and "Luizencontrole": the boards name them, but no page with their content exists, and the declaration does not invent one.

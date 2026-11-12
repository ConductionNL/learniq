---
kind: spec
depends_on: [school-portals-match-their-boards]
---

# Proposal: esdoornveen-public-pages-follow-the-boards

## Why

Proof run 3 (9 October) put the Esdoornveen public pages beside their boards. portaliq has since merged the options these boards need (site-catalogue-, site-article-page-, site-breadcrumb-, site-callouts-steps-and-tables- and site-home-follows-the-school-boards). The mbo declaration in `lib/Settings/portals/mbo.json` does not use them yet.

- Zoeken: the programme cards had the "Opleiding" tag and no meta line, the search label showed, the sort was by name and all programmes stood on one page.
- Artikel (Mechatronica): no lead, no "Wat leer je?" heading, the week table under a caption instead of a heading, Toelating and Na je diploma stacked, and no right column (Aanmelden, Eerst komen kijken?, Vraag over deze opleiding?).
- Contentpagina (Ziek melden): the melding became a paragraph and a bare button, no "Zo werkt het" heading, big plain step numbers, "Ben je jonger dan 18?" a tinted card, "Lukt inloggen niet?" in the main column, the table's first column not bold.
- Inloggen: the crumb read "Mijn Esdoornveen"; the eHerkenning card did not name the level it needs.
- Home: the Direct regelen icons sat in tinted circles; the header search opened the news only.

## What changes

`lib/Settings/portals/mbo.json` only:

- `portal.breadcrumb: "page"`; `headerSearch.route: "/opleidingen"` (board Zoeken is the programme search); the eHerkenning hint starts with "U heeft eHerkenning nodig op niveau [NIVEAU]."
- Home: `nlQuickTasks.iconStyle: "plain"`.
- `/opleidingen`: the board's intro line; `nlCatalogue` `sort: relevance`, `cardStyle: meta`, `labelHidden: true`, `pageSize: 5`.
- `/opleidingen/mechatronica`: lead paragraph, facts, "Wat leer je?" heading and list, "Zo ziet je week eruit" heading over a boxed table with row headers, Toelating and Na je diploma side by side; right column: a plain Aanmelden card with its button, Lesgeld and Boeken en gereedschap, a tinted "Eerst komen kijken?" list with "Meeloopdag aanvragen", and "Vraag over deze opleiding?".
- `/voor-studenten/ziek-melden`: lead paragraph; an `nlAlert` without heading with the button "Inloggen en ziek melden"; "Zo werkt het" over `display: numbered` steps; `rowHeaders: true` and the caption for screen readers only; "Ben je jonger dan 18?" as plain text; the side list `tinted`, with "Lukt inloggen niet?" as a plain card under it.
- `/nieuws`: `sectionHref: /zoeken`, `sectionLabel` and `kindLabel` "Nieuws".

## Decisions

- "Meeloopdag aanvragen" links to `/aanmelden`: the board names no address and the set has no page of its own for it.
- The Lesgeld and Boeken en gereedschap lines stand as a description list right under the Aanmelden card, not inside it: a melding holds no list.

## What an instance that loaded the set before sees

Nothing changes there: the provisioner never writes over a page that exists, and fills a portal field only when it is empty. A fresh load gets the new pages.

## Not in this change

- learniq, lane FIX-L: `breadcrumb` in `ExamplePortalProvisioner::FILLABLE` (without it a load does not write the key); the public programme index gives cards no `href`, no duration or crebo in `meta`, no "Richting" facet, and its summary is the long description (`PublicProgrammeIndex`).
- Story data: the board counts nine techniek programmes and names five; the other four are not on any board.
- portaliq: the "Techniek en ICT" pill above an article title, a photo placeholder without a photo (`nlImage`), facts as grey tiles (`nlDescriptionList`), a link in a sign-in card's hint ("Wachtwoord vergeten?"), a facet's explanation line ("BOL: vooral op school ...").

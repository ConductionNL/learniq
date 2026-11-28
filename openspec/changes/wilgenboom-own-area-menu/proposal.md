---
kind: spec
depends_on: [example-portal-declares-its-site, school-portals-match-their-boards]
---

# Proposal: wilgenboom-own-area-menu

## Why

Proof run 3 (9 October) found Fatima's menu in other groups than board MijnMenu ("Mijn omgeving", a group per child for "Oudergesprekken"), the conversations hard to find, the full footer and "Uitloggen" on a phone where board MobielHome draws her initials and a short footer, and an e-mail prompt no board shows. Portaliq's PQ-MIJN stack adds the keys for all of this to the portal record.

## What changes

In `lib/Settings/portals/po.json`, on the portal:

- `residentMenu.groups` as board MijnMenu: "Mijn Wilgenboom" (Overzicht, Berichten), "Mijn kinderen" (a row per child), "Regelen" (Afwezigheid, Oudergesprekken, Kalender), "Van school" (Nieuws, Rapporten en documenten), "Uw gegevens" (Mijn gegevens, Mijn account).
- `residentMenu.routes` `{"berichten": "messages"}`, and `inbox` left out of the menu: its notices stand on the conversations page.
- `residentMenu.phoneHeader: "person"` and `footer.compact` with "Telefoon: [telefoonnummer]", Toegankelijkheid and Privacy.
- `contactPrompt: {show: false}`.

"Rapporten en documenten" points at the report cards page (`learniq:parentReportCards`) until the documents page (lane FIX-P) lands; then the item moves to that page.

## Depends on

Portaliq #1423 (labelled menu items, `routes`), #1426 (`contactPrompt`) and #1434 (`phoneHeader`, `footer.compact`). Merge after those: the current portal schema refuses an object as a menu item.

## Not in this change (lane FIX-L)

- `contactPrompt` in `ExamplePortalProvisioner::FILLABLE`, so a load writes it.
- "Oudergesprekken" as one page: `parentConferences` is listed once per child (`perRecord`), so the group shows a row per child where the board shows one item with a count.
- The documents page itself.

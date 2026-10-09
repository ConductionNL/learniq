---
kind: spec
depends_on: [employer-portal-audience, school-portals-match-their-boards]
---

# Proposal: academy-own-area-follows-the-boards

## Why

Proof run 3 (9 October) put Linda's and Tom's own area beside boards MijnMenu, MijnOverzicht and MobielHome. The menu had no groups ("Mijn academie", "Cursussen", "Administratie", "Account"), "Berichten" stood under "Berichten en nieuws", `/mijn/berichten` fell through to "Mijn zaken", the phone header said "Uitloggen" where the board shows the initials, the full footer closed every phone page, and an e-mail prompt stood on pages that have none. Portaliq adds the keys for all of this (#1423 resident-menu-follows-the-boards, #1426 mijn-overview-follows-the-boards, #1432 mijn-messages-follow-the-boards, #1434 mijn-phone-chrome).

## What changes

Only `lib/Settings/portals/training.json`:

- `residentMenu.groups`: "Mijn academie" (overview, messages as "Berichten"), "Cursussen" (the employer's bookings as "Inschrijvingen", "Medewerkers", "Certificaten"), "Account" (details as "Mijn gegevens"); `residentMenu.routes` `{"berichten": "messages"}`; `inbox` left out of the menu.
- `residentMenu.phoneHeader: "person"` and `footer.compact` with the planning's number and the links to the route and privacy pages.
- `contactPrompt.show: false`.

## Depends on

Portaliq #1423, #1426, #1432 and #1434 (register 0.73.0). Merge this after them.

## Not in this change

- The "Administratie" group (Facturen, Bedrijfsgegevens): neither page exists. Invoices are academy-invoices-on-the-portal (shillinq); a company details page is a request to lane FIX-L.
- `residentMenu.person` ("4 medewerkers · via eHerkenning"): no collection carries those two lines yet (request to lane FIX-L).
- The footer line on the board reads "Kunt u donderdag niet?"; a fixed weekday would be wrong on every other course day, so the line says "een cursusdag".
- On an existing portal `contactPrompt` arrives only once `ExamplePortalProvisioner::FILLABLE` lists it, and `inbox` joins a `leaveOut` list only on a fresh load (requests to lane FIX-L).

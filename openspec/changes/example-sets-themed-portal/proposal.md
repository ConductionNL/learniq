# Proposal: each example set gets a themed portal

## Why

Testing the primary school set end to end (2026-09-30), the parent portal `wilgenboom` had to be made by hand. It had no theme, so `/apps/portaliq/site?portal=wilgenboom` rendered in the bare NL Design skeleton. Thematiq now ships four example token sets (thematiq#765), one per kind of school. Nothing connected them to learniq's example sets.

## What changes

- Loading an example set through the setup wizard gives its school a portal in portaliq, themed with the matching thematiq example set.
- `ExamplePortalProvisioner` finds the portal by slug. A missing portal is created, published, with the theme. A portal without a theme gets it. A portal with another theme is left alone. A second load writes nothing.
- The mapping: po to `example-basisschool`, vo to `example-voortgezet`, mbo to `example-college`, training to `example-opleider`. "College" is the mbo set. he has no example set of its own, so it borrows `example-college`. corporate borrows `example-opleider`.
- Without portaliq the step logs one line and writes nothing. Learniq checks the app id `portaliq` by name and keeps no code dependency on it.
- `occ learniq:example-set:portal <set>` runs the step on its own, for a set that was loaded before this change.

## How it writes

Portaliq ships no provisioning event or service for a leaf app. A portal is an OpenRegister object (register `portaliq`, schema `portal`), so learniq writes it through OpenRegister (ADR-022), as portaliq's own `InitializeDemoPortal` does. ADR-086 section 10 keeps a portal's theme with its operator. This is example data the operator asked for, and a theme somebody chose is never overwritten.

## Not in this change

- No `domains` and no `organisation` on a new portal. Binding a hostname and an identity provider stays a deployment step.
- No portal pages or menus. Learniq's `parent` contribution supplies the signed-in screens.

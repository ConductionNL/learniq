---
kind: spec
depends_on: [example-sets-are-the-four-schools, a-portal-declares-its-sign-in-modes]
---

# Proposal: example-portal-declares-its-site

## Why

The four school portals were designed as whole sites: a header menu, a home page with a notice, a hero, a "Direct regelen" card, news, a calendar and a sign-in card, content pages, a footer with three columns and a colophon, and sign-in pages that say who signs in how. Loading an example set gave the school a portal object with a theme and one sign-in mode (`digid`), and nothing else. Every page, menu and footer line had to be made by hand on a test instance, and the staff the pages name appeared as user ids ("po-leerkracht-09"). A fresh spin-up could not show the designs.

## What changes

- **One declaration per designed school** in `lib/Settings/portals/<set>.json` (po, vo, mbo, training): the portal (title, tagline, designed theme with a fallback, header variant, sign-in modes with their labels, header search, footer with description, colophon and a call to action), its menus (header at position 0, footer columns at position 1), its website pages as portaliq grid pages, its news items and the accounts of the staff its pages name.
- **`ExamplePortalProvisioner` writes the declaration, once.** A missing portal is created with everything. An existing portal keeps every value somebody set and only gets what is empty, key by key inside `authentication` and `footer`. A menu (by position and title), a page (by route) or a news item (by title) that exists is never written over. A second load writes nothing and reports zero created.
- **The designed theme, or a fallback.** The portal prefers its own thematiq token set (`wilgenboom`, `vaartveld`, `esdoornveen`, `warmtepompacademie`). Those sets ship in a thematiq release that may not be installed; when thematiq does not name the set or ships no token file for it, the portal gets the older example set (`example-basisschool`, ...), and the load logs it and goes on.
- **An older set's portal is left alone.** The old vo set's portal was `esdoornveen` and the old mbo set's was `vaartveld`, the slugs the mbo and vo sets now use. A portal found under the slug with the old title is reported as `kept-legacy`: nothing is written into it and no pages are added to it.
- **`occ learniq:example-set:load <set>`** loads a set the way the wizard does (objects, portal, site) and gives the staff the portal names a Nextcloud account with that display name (`--no-accounts` skips it). A new account gets a random password nobody knows; an existing account is named only when it has no name of its own. The wizard creates no accounts.
- **The sign-in modes come from the declaration** (finishes `a-portal-declares-its-sign-in-modes` T1): po `digid`; vo `nextcloud` and `digid`; mbo and training `nextcloud` and `eherkenning`.

## What an instance that loaded an old set sees

The po portal `wilgenboom` (made by hand on test instances) keeps its title, theme, modes and organisation; it gets the footer, the mode labels and the header search, and the menus and pages it does not have. An old vo or mbo portal is left as it is; the operator removes it, or keeps it, before the new set can take the slug.

## Depends on other lanes (declared now, rendered when they land)

- Lane L1 (portaliq chrome): `headerSearch`, `footer.cta` and `authentication.modeLabels` on the portal are stored now and ignored by portaliq until L1 reads them.
- Lane L2 (portaliq blocks): the page widgets `nlQuickTasks`, `nlNewsList`, `nlNewsArticle`, `nlEventList`, the `card` display of `nlSignIn` and the `steps` display of `nlList`, and the news keys `public` and `portal`, follow L2's block contract (`portal-build/l2/BLOCK-CONTRACT.md`, 2026-10-05). Until L2 lands, those widgets do not render.

## Not in this change

- Signed-in pages stay in PHP (`ParentSitePages`, `StudentPortalPages`, `TrainerSitePages`).
- Searching news and documents, the course catalogue and the opleidingen list (wave 2): the pages show the news list and the editorial pages without a search block.
- No `domains` and no `organisation` on a new portal, and no OIDC issuer: binding a hostname and an identity provider stays a deployment step.

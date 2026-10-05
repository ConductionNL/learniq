## ADDED Requirements

### Requirement: Each example set declares its portal site in one file

The po, vo, mbo and training example sets MUST each ship `lib/Settings/portals/<set>.json` naming the portal (slug, title, tagline, theme and fallback theme, sign-in modes, footer), its menus, its website pages, its news items and the accounts its pages name. Every menu link inside the site MUST point at a page the same declaration declares or at `/mijn`. Every account MUST be a person of the set and every news audience an object of the set. The copy MUST contain no em or en dashes.

#### Scenario: The four designed schools declare their sites
- **GIVEN** the shipped declarations
- **WHEN** they are read
- **THEN** po declares `wilgenboom` "Mijn Wilgenboom" with mode `digid`, vo `vaartveld` with `nextcloud` and `digid`, mbo `esdoornveen` and training `warmtepompacademie` with `nextcloud` and `eherkenning`, each with a home page, a header menu and a colophon
- @e2e exclude configuration files, covered by PHPUnit `ExamplePortalProvisionerTest::testEveryDesignedSchoolDeclaresItsSite` and `ExamplePortalDeclarationsTest`

### Requirement: Loading a set writes its declared site once

Loading an example set MUST create a missing portal with the whole declaration, and every declared menu, page and news item the portal does not have. A menu MUST be matched by position and title, a page by route, a news item by title; a match MUST NOT be written. An existing portal MUST keep every value it has and MUST only get declared values for fields that are empty, key by key inside `authentication` and `footer`. A failure MUST NOT fail the import of the set.

#### Scenario: A second load writes nothing
- **GIVEN** the po set was loaded and its site written
- **WHEN** the operator loads the po set again
- **THEN** the portal is `unchanged`, zero menus, pages and news items are created, and OpenRegister receives no write
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAFreshLoadWritesTheSiteAndASecondLoadWritesNothing`; the rendered pages are checked by `tests/e2e/portal-design/`

#### Scenario: An existing portal keeps what was chosen
- **GIVEN** portal `wilgenboom` with theme `rijkshuisstijl`, modes `digid` and `nextcloud`, and a colophon
- **WHEN** the operator loads the po set
- **THEN** the theme, the modes, the title, the organisation and the colophon stay, and the empty footer description and the mode labels are filled
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAnExistingPortalKeepsItsChoicesAndGetsWhatIsEmpty`

#### Scenario: An edited page is never written over
- **GIVEN** portal `wilgenboom` already has a page at `/` and a header menu
- **WHEN** the operator loads the po set
- **THEN** neither is written and both are counted as kept
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAnEditedPageOrMenuIsKept`

#### Scenario: An older set's portal under the slug is left alone
- **GIVEN** portal `esdoornveen` titled "Ouderportaal Esdoornveen" from the old vo set
- **WHEN** the operator loads the mbo set
- **THEN** the answer is `kept-legacy` and nothing is written
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAnOlderSetsPortalUnderTheSlugIsLeftAlone`

### Requirement: A portal gets its designed theme and falls back when thematiq lacks it

A new portal MUST get the declaration's `theme` when the installed thematiq names that id in `token-sets.json` and ships `css/tokens/<id>.css`, and the declaration's `themeFallback` otherwise. A missing set MUST be logged and MUST NOT fail the load.

#### Scenario: Thematiq without the new set
- **GIVEN** thematiq does not ship `wilgenboom`
- **WHEN** the operator loads the po set
- **THEN** the portal gets `example-basisschool` and the answer says it is the fallback
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAFreshLoadWritesTheSiteAndASecondLoadWritesNothing`, `testANamedSetWithoutItsTokenFileGivesTheFallback` and `testThePortalGetsTheDesignedThemeWhenThematiqShipsIt`

### Requirement: The staff a portal names have accounts with those names

`occ learniq:example-set:load <set>` MUST give every account the declaration lists a Nextcloud account whose display name is the declared one, unless `--no-accounts` is given. A missing account MUST be created with a random password that is never shown or logged. An existing account MUST be named only when its display name is empty or its user id. The account MUST join each declared group that exists; no group is created and no membership removed. The setup wizard MUST NOT create accounts.

#### Scenario: Meester Daan has his name
- **GIVEN** a fresh instance
- **WHEN** the operator runs `occ learniq:example-set:load po`
- **THEN** account `po-leerkracht-09` exists with display name "Meester Daan" in group `instructors`, and a second run reports it as kept
- @e2e exclude covered by PHPUnit `ExampleAccountProvisionerTest`; the portal pages that show the name are checked by `tests/e2e/portal-design/wilgenboom.spec.ts`

### Requirement: An example set loads from occ

`occ learniq:example-set:load <set>` MUST import the set as the setup wizard does, provision its portal and site, print what it did, and exit non-zero for an unknown set, a failed import, a failed portal or a failed account.

#### Scenario: A spin-up loads the four sets
- **GIVEN** a fresh instance with OpenRegister, portaliq and thematiq
- **WHEN** a script runs `occ learniq:example-set:load` for po, vo, mbo and training
- **THEN** each exits 0 and prints the object count, the portal status with its theme, and the menus, pages, news and accounts created
- @e2e exclude an occ command; its parts are covered by PHPUnit, the result by `tests/e2e/portal-design/`

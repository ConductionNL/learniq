## ADDED Requirements

### Requirement: Esdoornveen's public pages follow their boards

The mbo portal declaration MUST name a page in the breadcrumb by its own title (`breadcrumb: page`), MUST send the header search to `/opleidingen`, and its eHerkenning card MUST say that eHerkenning is needed at level [NIVEAU]. The `/opleidingen` catalogue MUST sort by best match, show meta cards with the search label hidden, five programmes a page. The Mechatronica page MUST open with a lead, put "Wat leer je?" and "Zo ziet je week eruit" as headings, show Toelating and Na je diploma side by side, and hold a right column of a plain Aanmelden card with its button, the costs, a tinted "Eerst komen kijken?" list and "Vraag over deze opleiding?". The Ziek melden page MUST open with a lead, a melding with the button "Inloggen en ziek melden", "Zo werkt het" over numbered steps, the table with row headers, and "Lukt inloggen niet?" as a plain card under the side list; under the side list of a content page only plain cards MAY stand. The home's Direct regelen icons MUST be plain.

#### Scenario: A fresh load gives the programme article its right column
- **GIVEN** a fresh instance with portaliq
- **WHEN** the operator loads the mbo set
- **THEN** `/opleidingen/mechatronica` shows the Aanmelden card with "Aanmelden voor Mechatronica" at the top of the right column
- @e2e exclude declaration file, covered by PHPUnit `EsdoornveenPublicPagesTest::testTheProgrammeArticleFollowsBoardArtikel`; the rendered page is compared by `tests/e2e/portal-design/`

#### Scenario: The sick report page carries its button in the melding
- **GIVEN** a fresh instance with portaliq
- **WHEN** a student opens `/voor-studenten/ziek-melden`
- **THEN** the melding "Het kan vanaf je telefoon en kost een halve minuut." carries the button "Inloggen en ziek melden", and "Zo werkt het" stands over three numbered steps
- @e2e exclude declaration file, covered by PHPUnit `EsdoornveenPublicPagesTest::testTheSickReportPageFollowsBoardContentpagina`

#### Scenario: The programme search shows five meta cards a page
- **GIVEN** a fresh instance with portaliq
- **WHEN** a visitor opens `/opleidingen`
- **THEN** the cards show their meta line, sorted by best match, five a page
- @e2e exclude declaration file, covered by PHPUnit `EsdoornveenPublicPagesTest::testTheProgrammeSearchFollowsBoardZoeken`

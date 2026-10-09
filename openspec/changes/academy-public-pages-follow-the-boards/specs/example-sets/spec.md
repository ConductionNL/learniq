## ADDED Requirements

### Requirement: The academy's public pages follow their boards

The training portal declaration MUST name each page in the breadcrumb by its own title (`portal.breadcrumb: page`). Its home MUST show "U oefent op echte opstellingen" as a hero with a level-2 heading, the photo marked by `asideImage.label` and no photo address, and its two links as text links. The content page "Medewerkers inschrijven" MUST show the level-2 headings "Dit hebben wij van elke deelnemer nodig" and "Verplaatsen of annuleren", the sentence "Weet u nog niet wie er gaat?" as a paragraph and not as a melding, its table with row headers and a hidden caption, and "Betalen" and "Overleggen met de planning" as plain melding cards in the right column under the side list. The course catalogue MUST declare `cardStyle: meta` and `searchPlacement: rail`. The buttons the boards draw with a chevron MUST declare `icon: chevron`.

#### Scenario: The content page puts payment and planning beside the main text
- **GIVEN** the training portal declaration
- **WHEN** the provisioner writes the page "Medewerkers inschrijven"
- **THEN** the right column holds the side list at the top, then "Betalen" and "Overleggen met de planning" as `nlAlert kind: plain`
- **AND** no markdown block in the main column repeats them
- @e2e exclude declaration file, covered by PHPUnit `AcademyPublicPagesTest::testTheContentPageFollowsTheBoard`; the rendered pages are compared by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

#### Scenario: The practice hall band marks its photo
- **GIVEN** the training portal declaration
- **WHEN** the provisioner writes the home page
- **THEN** "U oefent op echte opstellingen" is a hero with heading level 2, an `asideImage` label starting with "[FOTO: " and two text links
- @e2e exclude declaration file, covered by PHPUnit `AcademyPublicPagesTest::testTheHomeMarksThePracticeHallPhoto`

#### Scenario: The catalogue searches from the filter column
- **GIVEN** the training portal declaration
- **WHEN** a visitor opens /cursusaanbod
- **THEN** the catalogue block declares `searchPlacement: rail`, `cardStyle: meta` and the breadcrumb follows the page title
- @e2e exclude declaration file, covered by PHPUnit `AcademyPublicPagesTest::testTheCatalogueAndBreadcrumbFollowTheBoards`

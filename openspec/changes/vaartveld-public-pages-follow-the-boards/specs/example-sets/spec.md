## ADDED Requirements

### Requirement: Vaartveld's public pages follow their boards

The vo portal declaration MUST name a page in the breadcrumb by its own title (`breadcrumb: page`). Its home MUST say the school is for vmbo-t, havo and vwo, show "Ons onderwijs" in three columns (Vmbo-t, Havo, Vwo), draw the second hero action as a text link and the first with a chevron, draw the "Direct regelen" icons plain, and show the agenda rows without links. The search page "Nieuws en documenten" MUST filter by "Soort", hide the field's label, and declare the school's documents (toetsroosters, the PTA, letters to parents, rules and the school guide) as public index items with a kind, a date, a line of meta and the facets Soort, Afdeling and Leerjaar. The news article MUST carry the "Nieuws" pill and a crumb through "Nieuws en documenten", with the light "Kom je ook?" card, "Om alvast te lezen" and "Meer nieuws" beside it. The content page "Ziek melden en verlof" MUST put its "Ziek melden" button inside the callout, draw its table's first column as row headers, and show "18 jaar of ouder?" and "Is uw kind lang ziek?" as plain cards under the side list.

#### Scenario: The home names vmbo-t and links its second action as text
- **GIVEN** the vo portal declaration
- **WHEN** portaliq renders the home
- **THEN** the hero reads "de school voor vmbo-t, havo en vwo", "Lees wat er speelt op school" is a text link, and "Ons onderwijs" opens with the column "Vmbo-t"
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

#### Scenario: The search page declares the board's documents
- **GIVEN** the vo portal declaration
- **WHEN** a reader looks at `publicIndex.documents`
- **THEN** it holds the toetsrooster of 4 havo, the PTA 4 havo, the letter "Toetsweek 1 en de herkansingen" and "Regels tijdens een toetsweek", each with a kind and the facet "Soort"
- @e2e exclude the documents reach the page only once learniq's public index reads them (FIX-L); covered by PHPUnit `VaartveldPublicPagesTest::testTheSearchPageFollowsTheBoard`

#### Scenario: The content page holds its button inside the callout
- **GIVEN** the vo portal declaration
- **WHEN** portaliq renders "Ziek melden en verlof"
- **THEN** "Ziek melden" is the action of "Melden duurt een minuut" and the side column shows "18 jaar of ouder?" and "Is uw kind lang ziek?" under "Meer praktische zaken"
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

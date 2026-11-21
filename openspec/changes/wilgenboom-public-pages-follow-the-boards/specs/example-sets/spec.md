## ADDED Requirements

### Requirement: The Wilgenboom public pages use the options their boards need

The po declaration MUST declare `breadcrumb: "page"` on its portal. Its search page MUST filter by kind ("Soort") and by audience ("Voor wie", as radios) and hide the field's visible label. Its article page MUST run the crumb through "Nieuws en documenten", show the pill "Nieuws" and put the light sign-in card above "Meer nieuws" in the right column. Its content page on reporting absence MUST put the "Afwezig melden" button inside the "Online melden" callout, show "Liever bellen?" as a plain card with the number in bold, end with a warning callout and give the table row headers.

#### Scenario: A fresh load shows the search filters and the article's crumb
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set and a visitor opens `/zoeken` and a news item
- **THEN** the search page shows the filters "Soort" and "Voor wie" and the article's crumb reads "Home › Nieuws en documenten › <title>"
- @e2e exclude declaration file, covered by PHPUnit `WilgenboomDeclarationsTest`; the rendered pages are compared by `tests/e2e/portal-design/`

#### Scenario: The content page holds its button inside the callout
- **GIVEN** a fresh load of the po set
- **WHEN** a visitor opens `/praktisch/afwezig-melden`
- **THEN** "Afwezig melden" is a button inside "Online melden", there is no separate button below the cards, and the last paragraph is a warning
- @e2e exclude declaration file, covered by PHPUnit `WilgenboomDeclarationsTest`

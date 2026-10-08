## ADDED Requirements

### Requirement: The portal declarations follow their boards

Each portal declaration MUST give the footer one Contact column, from `footer.contact`, and no footer menu named "Contact". A page MUST NOT start with an `nlHeading` that repeats its own title. A hero with a search box MUST declare `headingVisible: true`. On the po and vo home pages the sign-in card and the calendar MUST stand beside the news, the calendar right under the card. The content page that explains how to report absence (po, vo, mbo) or enrol staff (training) MUST put its side list at the top of the right column and keep every other block in the left 8 columns. The mbo home MUST show "Kies je richting" over four cards.

#### Scenario: A fresh load renders one footer Contact column and one page title
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** the footer has one Contact column and "Uw kind afwezig melden" appears once as the page heading
- @e2e exclude declaration file, covered by PHPUnit `ExamplePortalDeclarationsTest::testTheDeclarationsFollowTheBoards`; the rendered pages are compared by `tests/e2e/portal-design/`

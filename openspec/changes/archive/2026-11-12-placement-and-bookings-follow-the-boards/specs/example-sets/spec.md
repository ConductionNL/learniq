## ADDED Requirements

### Requirement: The homes mark the lead photo, and Esdoornveen invites training companies with links

The po, vo and mbo homes MUST mark where the lead news photo goes with the board's words. Esdoornveen's home MUST offer training companies a card with the links "Inloggen als leerbedrijf" and "Leerbedrijf worden", not a second sign-in card.

#### Scenario: Esdoornveen's home
- **GIVEN** the mbo set loaded on a fresh instance
- **WHEN** a visitor opens Esdoornveen's home
- **THEN** one dark sign-in card shows (Mijn Esdoornveen), and "Voor leerbedrijven" is a light card with two links
- @e2e exclude declaration covered by PHPUnit `ExamplePortalDeclarationsTest::testTheHomesMarkTheLeadPhotoAndInviteCompaniesWithLinks`

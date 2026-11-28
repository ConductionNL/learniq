## ADDED Requirements

### Requirement: The Wilgenboom own area follows its menu and phone boards

The po declaration MUST group the guardian's menu as board MijnMenu: "Mijn Wilgenboom", "Mijn kinderen", "Regelen", "Van school" and "Uw gegevens", with the conversations named "Berichten" and reachable at `/mijn/berichten`. Every learniq page the groups name MUST exist in the guardian's manifest. On a phone the own area MUST show the person in the header and the short footer. The portal MUST leave out the e-mail prompt.

#### Scenario: Fatima's menu on a fresh load
- **GIVEN** a fresh load of the po set on a portaliq with the PQ-MIJN keys
- **WHEN** Fatima opens `/mijn`
- **THEN** the menu shows the five groups of board MijnMenu and "Berichten" opens the conversations
- @e2e exclude declaration file, covered by PHPUnit `WilgenboomOwnAreaTest`; the rendered menu is compared by `tests/e2e/portal-design/`

#### Scenario: Fatima on her phone
- **GIVEN** the same portal
- **WHEN** Fatima opens `/mijn` on a phone
- **THEN** the header shows "FH" and the page ends in "Telefoon: [telefoonnummer]", Toegankelijkheid and Privacy
- @e2e exclude declaration file, covered by PHPUnit `WilgenboomOwnAreaTest`

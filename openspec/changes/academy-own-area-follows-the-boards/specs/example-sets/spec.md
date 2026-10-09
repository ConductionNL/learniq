## ADDED Requirements

### Requirement: The academy's own area follows its boards

The training portal declaration MUST group its own-area menu as board MijnMenu: "Mijn academie" with the overview and the conversations labelled "Berichten", "Cursussen" with the employer's bookings labelled "Inschrijvingen", her employees labelled "Medewerkers" and her certificates labelled "Certificaten", and "Account" with the details labelled "Mijn gegevens". Every learniq item it names MUST be a page of the employer contribution. It MUST route `/mijn/berichten` to the conversations, leave the inbox out of the menu, show the person in the phone header, declare a compact footer with the planning's number, and leave out the e-mail prompt.

#### Scenario: Linda's menu reads like the board
- **GIVEN** the training portal declaration
- **WHEN** Linda opens Mijn academie
- **THEN** the menu shows "Mijn academie", "Cursussen" and "Account" with "Berichten", "Inschrijvingen", "Medewerkers", "Certificaten" and "Mijn gegevens"
- @e2e exclude declaration file, covered by PHPUnit `AcademyOwnAreaDeclarationTest::testTheMenuHasTheBoardsGroups`; the rendered menu is compared by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

#### Scenario: Tom's phone shows his initials and the short footer
- **GIVEN** the training portal declaration
- **WHEN** Tom opens Mijn academie on a phone
- **THEN** the header shows the person, the footer is the compact one and no e-mail prompt shows
- @e2e exclude declaration file, covered by PHPUnit `AcademyOwnAreaDeclarationTest::testThePhoneChromeFollowsMobielHome`

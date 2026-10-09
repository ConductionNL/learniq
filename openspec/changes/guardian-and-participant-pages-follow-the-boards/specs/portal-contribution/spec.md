## ADDED Requirements

### Requirement: The guardian overview and absence page are about both children

The guardian's overview MUST NOT switch between children. Its task card MUST leave out a conference round where one of her children already has a time. The absence page MUST show the form and the reports of all her children, newest first, and MUST NOT be per child.

#### Scenario: Fatima's overview with Vera booked
- **GIVEN** Fatima, whose daughter Vera has a conversation time and whose son Sami has none
- **WHEN** she opens Mijn Wilgenboom
- **THEN** no switcher shows, both children's cards show, and only Sami's round asks her to pick a time
- @e2e exclude declaration covered by PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`; the rendered page by `tests/e2e/portal-design/wilgenboom.spec.ts`

### Requirement: The participant reads his next course day once

The participant's overview MUST show his next course day once, as the highlight. The list under it MUST be headed "After that" and MUST skip that day.

#### Scenario: Tom's overview
- **GIVEN** Tom, with course days on 8 October and 3 November
- **WHEN** he opens Mijn academie
- **THEN** the highlight shows 8 October, and "Daarna" lists 3 November
- @e2e exclude covered by PHPUnit `ParticipantSitePagesTest::testTheNextCourseDayShowsOnce`

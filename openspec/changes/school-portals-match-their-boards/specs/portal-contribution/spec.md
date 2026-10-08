## ADDED Requirements

### Requirement: The guardian overview holds only what the board shows

The guardian's overview MUST hold, in order, the greeting with the absence button, what is still to do, the children, the school news and this month's calendar, and nothing else. The calendar MUST cover the current month. A booked parent-teacher conversation MUST read as "Parent-teacher conversation" with the teacher's name under it, never as the time picker's line.

#### Scenario: Fatima's overview
- **GIVEN** Fatima Hulstkamp, guardian of Vera and Sami, signed in to De Wilgenboom
- **WHEN** she opens Mijn Wilgenboom
- **THEN** she sees the greeting, "Wat u nog moet doen", "Mijn kinderen", "Nieuw van school" and "Deze maand", and no figures, reports, grades or messages
- @e2e exclude covered by PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`; the rendered page by `tests/e2e/portal-design/wilgenboom.spec.ts`

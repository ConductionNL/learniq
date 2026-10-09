## ADDED Requirements

### Requirement: The guardian overview holds only what the board shows

The guardian's overview MUST hold, in order, the greeting with the absence button, what is still to do, the children, the school news and this month's calendar, and nothing else. The calendar MUST cover the current month. A booked parent-teacher conversation MUST read as "Parent-teacher conversation" with the teacher's name under it, never as the time picker's line.

#### Scenario: Fatima's overview
- **GIVEN** Fatima Hulstkamp, guardian of Vera and Sami, signed in to De Wilgenboom
- **WHEN** she opens Mijn Wilgenboom
- **THEN** she sees the greeting, "Wat u nog moet doen", "Mijn kinderen", "Nieuw van school" and "Deze maand", and no figures, reports, grades or messages
- @e2e exclude covered by PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`; the rendered page by `tests/e2e/portal-design/wilgenboom.spec.ts`

### Requirement: A child's card says where the child is today

The children cards on the guardian overview MUST declare a derived status, never a stored one: a report of the child in `submitted` or `approved` whose days cover today reads "Reported sick" ("Ziek gemeld"); any other school day reads "At school" ("Op school"); a weekend or holiday shows no chip. Both labels MUST go through the contribution's label translation, so a Dutch portal reads the Dutch words. Every field the status reads MUST be projected by `parentExcuseRequests`.

#### Scenario: Sami is reported sick, Vera is at school
- **GIVEN** Fatima reported Sami sick for today and Vera has no report
- **WHEN** she opens Mijn Wilgenboom on a school day
- **THEN** Sami's card reads "Ziek gemeld" and Vera's "Op school"
- @e2e exclude declared now, rendered once portaliq reads the card `status` lookup (lane FIX-P); the declaration is covered by PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`

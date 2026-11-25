## ADDED Requirements

### Requirement: A transition stores the reason its board dialog sends

Credential `revoke` MUST declare `revocationReason` as a required input. Enrolment `withdraw` MUST declare `withdrawReason` as an optional input, so a learner's own sign-off still withdraws without one.

#### Scenario: A certificate is revoked with a reason
- **GIVEN** an issued Credential
- **WHEN** a staff member revokes it with the reason "Fraude vastgesteld: examen ongeldig"
- **THEN** the Credential is revoked and stores that `revocationReason`

#### Scenario: A learner signs off without a reason
- **GIVEN** an active Enrolment of the learner
- **WHEN** the learner withdraws from the catalogue
- **THEN** the Enrolment is withdrawn with no `withdrawReason`

### Requirement: A conference round lists its evenings

ConferenceRound MUST carry `evenings`, a list of a date with a start and end time (`HH:MM`), from which the availability dialog draws one row per evening.

#### Scenario: The availability dialog of the vo mentor round
- **GIVEN** the vo mentor round of 3HV-B
- **WHEN** its mentor opens the availability dialog
- **THEN** it shows Tuesday 13 October 16:10 to 17:20 and Thursday 15 October 18:30 to 19:40

### Requirement: A list row has a name

Application MUST name its row with the applicant's given and family name, and ExchangePartnerApproval with its partner name, so the row menu reads "Acties voor <name>".

#### Scenario: An application row
- **GIVEN** an Application of Daan Visser
- **WHEN** the admissions list shows it
- **THEN** its row menu is labelled with "Daan Visser"

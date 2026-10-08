## ADDED Requirements

### Requirement: The placement page reads in words and shows the hours

Every field the student's placement collection projects, except her own reference, MUST carry a label, and its state MUST read in words for every state of the schema. The placement page MUST show the hours bar under the steps. A step MUST NOT carry a date beside a line that names the same day.

#### Scenario: Milan opens his placement
- **GIVEN** Milan's placement at Bakker Techniek BV
- **WHEN** he opens it from "BPV en uren"
- **THEN** the record reads "Van", "Tot en met", "Afgesproken uren" and "Status: Loopt", the hours bar shows under "Waar sta je?", and "27 augustus 2026" appears once
- @e2e exclude covered by PHPUnit `PortalContributionProviderTest::testThePlacementReadsInWordsAndShowsTheHours` and `BpvPlacementStepsTest`; the page by `tests/e2e/portal-design/esdoornveen.spec.ts`

### Requirement: A booking lists its participants, and the certificates group per certificate

An opened booking MUST list its participants as rows, each with the participant's name, the certificate line and a pill for the details. The employer's certificates MUST group per certificate.

#### Scenario: Linda opens the F-gassen booking
- **GIVEN** Linda Jansen and the F-gassen booking for Tom, Youssef and Sanne
- **WHEN** she opens it
- **THEN** Youssef's row reads "Geboortedatum ontbreekt", each row names its certificate line, and "Geboortedatum invullen" is offered
- @e2e exclude covered by PHPUnit `EmployerSitePagesTest::testABookingListsItsParticipantsAndCertificatesGroup`; the page by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

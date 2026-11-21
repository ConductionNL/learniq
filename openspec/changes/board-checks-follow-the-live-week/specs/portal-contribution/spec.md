## ADDED Requirements

### Requirement: An opened booking names its fields in the reader's language

Every field an employer reads on an opened booking MUST carry a label from learniq's catalogue, and the booking's state MUST read as words, so no field falls back to its English schema title or a stored value.

#### Scenario: Linda opens the F-gassen booking
- **GIVEN** Linda reads Mijn academie in Dutch
- **WHEN** she opens the F-gassen booking
- **THEN** its fields read "Inschrijvingsnummer", "Dagen", "Tijd", "Waar" and "Ingeschreven op", and its state "Bevestigd"
- @e2e exclude covered by PHPUnit `EmployerSitePagesTest::testAnOpenedBookingNamesEveryField`

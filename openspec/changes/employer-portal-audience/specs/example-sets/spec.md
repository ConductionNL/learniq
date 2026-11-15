## ADDED Requirements

### Requirement: The training set holds the employer's story

The training set MUST seed Jansen Installatietechniek BV as a `client-organisation` with its four employees pointing at it, and the bookings I-2026-0377, I-2026-0412, I-2026-0425 and I-2026-0431 with their participants' enrolments, carrying exactly the readable copies and status the server would derive. The training portal declaration MUST give Linda Jansen (`training-contactpersoon-007`) a Nextcloud account.

#### Scenario: The seeded bookings agree with the server
- **GIVEN** the training set
- **WHEN** EmployerBookingFacts derives each seeded booking
- **THEN** every derived field equals the seeded one, and the statuses are completed, waiting-for-you, confirmed and received
- @e2e exclude seed check, covered by PHPUnit `EmployerBookingFactsTest::testTheSeededBookingsAgreeWithTheServer`

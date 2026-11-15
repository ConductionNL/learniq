---
status: proposed
---

# Spec: portal-contribution

## ADDED Requirements

### Requirement: A booking an employer makes belongs to the company's organisation

When an employer books places through the portal, learniq SHALL create the booking acting for the OpenRegister organisation of the company row, when the company has one. Otherwise the booking SHALL be created as before. An organisation that does not exist SHALL create no booking.

#### Scenario: The company has an organisation
- **GIVEN** a company row whose `@self.organisation` is the training provider's organisation
- **WHEN** its employer books one place on an open edition
- **THEN** the booking is created acting for that organisation
- @e2e exclude Context wiring; pinned by EmployerBookingOrganisationTest

#### Scenario: The company has no organisation
- **GIVEN** a company row without `@self.organisation`
- **WHEN** its employer books one place
- **THEN** the booking is created as before
- @e2e exclude Fallback branch; pinned by EmployerBookingOrganisationTest

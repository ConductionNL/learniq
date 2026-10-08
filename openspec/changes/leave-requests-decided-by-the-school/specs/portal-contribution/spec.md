## ADDED Requirements

### Requirement: A guardian asks leave for her own child and sees the decision

The parent audience MUST declare an action `requestLeave` that creates a `leave-request` for one of the guardian's own children, with the server stamping `learnerRef` and `requestedByRef` from her session and never from the form, and a collection `parentLeaveRequests` of her own requests with the days, the state in words and the decision note.

#### Scenario: Fatima asks leave for Vera
- **GIVEN** Fatima is signed in with DigiD
- **WHEN** she asks leave for Vera for a family occasion on Friday 6 November
- **THEN** one request exists for Vera, naming Fatima, and her list shows it as "Ingediend" with the date the school will answer by
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

#### Scenario: Not her child
- **GIVEN** a request that names a classmate of Vera
- **WHEN** Fatima submits it
- **THEN** it is refused and nothing is stored
- @e2e exclude refusal asserted from the caller in the action's unit tests

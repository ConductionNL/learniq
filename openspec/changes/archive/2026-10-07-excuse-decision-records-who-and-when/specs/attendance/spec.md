## ADDED Requirements

### Requirement: A decision on an absence report records who decided and when
When an `ExcuseRequest` moves to `approved` or `rejected`, the transition MUST stamp `decidedBy` with the signed-in user who made the decision and `decidedAt` with the time of the decision.

#### Scenario: The guardian sees when the teacher approved the report
- **GIVEN** a guardian's absence report in `submitted`
- **WHEN** the group teacher approves it
- **THEN** the report carries `decidedBy` = the teacher's user id and a `decidedAt`
- **AND** the guardian's portal list shows the report as approved with that date
- @e2e tests/e2e/po-parent-flows.spec.ts

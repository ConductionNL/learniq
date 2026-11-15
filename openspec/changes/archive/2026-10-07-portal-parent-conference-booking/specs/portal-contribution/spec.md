## ADDED Requirements

### Requirement: A guardian picks the child from their own children
Every `parent` create action that names a child MUST declare `learnerRef` as a required cross reference over `learner-profile` scoped by `guardianRefs` and the `guardianRef` claim, and MUST offer the child as a choice among the guardian's own children. Every parent collection MUST declare readable `columns`.

#### Scenario: A guardian reports an absence by picking the child
- **GIVEN** a guardian with one child
- **WHEN** she opens "Report a child's absence"
- **THEN** the child field lists her child by name
- **AND** a request naming another child is refused by portaliq before it is stored
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: The parent contribution names the guardian's news audience
The `parent` contribution MUST declare `guardianAudience` with `children: parentChildren`, `schoolField: schoolId` and `groups: {collection: parentGroupMemberships, field: cohortId}`, and every named collection MUST exist in the contribution.

#### Scenario: A school-wide news item reaches the guardian
- **GIVEN** a news item targeted at Voorbeeldschool De Wilgenboom
- **WHEN** guardian Fatima Hulstkamp opens the news page in the portal
- **THEN** the item is listed
- @e2e tests/e2e/po-parent-flows.spec.ts

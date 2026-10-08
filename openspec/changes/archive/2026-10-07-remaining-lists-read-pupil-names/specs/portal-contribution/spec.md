## ADDED Requirements

### Requirement: A guardian reads the newest absence first
The `parentExcuseRequests` collection MUST declare `defaultSort: { field: dateFrom, direction: desc }`, and `dateFrom` MUST be one of the fields it projects.

#### Scenario: Four absences
@e2e exclude Contribution content. Pinned by PortalContributionProviderTest; the order on screen is portaliq's, pinned there by tests/mijn-lists.spec.mjs. The live check is in the PR.
- **GIVEN** Vera has absences from 1 October, 5 October, 2 October and 25 September
- **WHEN** her guardian opens the absences
- **THEN** they read 5 October, 2 October, 1 October, 25 September

## ADDED Requirements

### Requirement: The guardian reads the name of the child's group
The parent audience's group memberships collection MUST show the name of each of the child's groups, read from the enrolment's own readable copy (`cohortName`), never the group's uuid. The guardian MUST NOT gain a read of any object beyond their own children's enrolments for it, and the news audience MUST keep matching on `cohortId`.

#### Scenario: Fatima reads Vera's group
@e2e exclude Portal contribution content, rendered by portaliq. Pinned by tests/Unit/Portal/PortalContributionProviderTest.php; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera Hulstkamp is enrolled in Groep 7
- **WHEN** her guardian Fatima opens the parent portal
- **THEN** the group column reads "Groep 7"
- **AND** Fatima reads no cohort object

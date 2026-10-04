## RENAMED Requirements

- FROM: `### Requirement: A learner's personal number is encrypted and readable only by administration and compliance`
- TO: `### Requirement: A learner's personal number is readable only by administration and compliance`

## MODIFIED Requirements

### Requirement: A learner's personal number is readable only by administration and compliance
LearnerProfile MUST hold the persoonsgebonden nummer in `personalNumber`, a stored, filterable
property (not `x-openregister-encrypted`: an encrypted property is not filterable, so an upload row
could never be matched on it; DECISIONS row 54), with its kind in `personalNumberType` (`bsn` or
`onderwijsnummer`). Both properties MUST carry a property authorization whose `read` and `update`
name only `administration-managers` and `compliance-officers`, and `personalNumber` MUST ask for a
reveal audit (`audit: true`). Neither property MAY be a facet or search field; `personalNumber` MAY
be filtered by a server-side match (the external-training upload).

#### Scenario: a teacher reads a learner profile
- GIVEN a learner profile with a `personalNumber`
- WHEN a user in `instructors` reads it
- THEN the response has no `personalNumber` and no `personalNumberType`

#### Scenario: the register declares the protection
- GIVEN the learniq register
- WHEN LearnerProfile is loaded
- THEN `personalNumber` is a stored property that is not flagged encrypted, and both properties authorize only `administration-managers` and `compliance-officers`

#### Scenario: an upload row is matched on the personal number
- GIVEN a learner profile whose `personalNumber` was set with an update
- WHEN a compliance officer uploads a row whose learner column is that number
- THEN the row is matched to that learner in the officer's tenant

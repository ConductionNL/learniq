## ADDED Requirements

### Requirement: An external assessor lands on what is shared with him and until when

The `external-assessor` audience MUST declare pages under `group: Mijn omgeving`, with `menu: false` on the default collection pages. The first MUST be `eaOverview`, "Gedeeld met u", with `home: true`, showing one row per share with the candidate's name, the portfolio title, the end date or "Gedeeld zonder einddatum", and how many parts are shared. An expired share MUST stay listed as "Verlopen", with nothing to open. The audience MUST stay read-only: no action is added. Design of record: esdoornveen/Beoordelaar.

#### Scenario: Henk sees what is shared and until when
- GIVEN assessor Henk Vos with a share ending 31 January 2027
- WHEN he signs in on the site for assessors
- THEN "Gedeeld met u" shows one row per share with the candidate's name
- AND that row reads "Gedeeld tot 31 januari 2027"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A share without an end date
- GIVEN a share to Henk with no `expiresAt`
- WHEN he opens "Gedeeld met u"
- THEN the row reads "Gedeeld zonder einddatum" and "Blijft open"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A revoked share disappears
- GIVEN a student revokes one of Henk's shares
- WHEN he opens "Gedeeld met u"
- THEN that candidate is no longer listed
- @e2e exclude server filter; pinned by PortalContributionProviderTest on `filter: lifecycle active`

### Requirement: NEW: A share names its candidate and portfolio

`portfolio-share` MUST carry `portfolioTitle` and `learnerName`, readable copies stamped by the server when the share is created and never written by a client. `eaSharedPortfolios` MUST project both. This is new work: a share holds only pointers today.

#### Scenario: The candidate's name on the share
- GIVEN a share of Milan de Groot's portfolio "Mechatronica" to Henk
- WHEN Henk opens "Gedeeld met u"
- THEN the row reads "Milan de Groot, portfolio Mechatronica"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A client cannot rename the candidate
- GIVEN a share create that sends `learnerName` "Iemand anders"
- WHEN the server saves it
- THEN `learnerName` holds the name of the portfolio's own learner
- @e2e exclude server stamp; covered by a unit test of the stamp written with the build

### Requirement: NEW: An external assessor opens the shared entries, and only those

The `external-assessor` audience MUST declare `eaSharedPortfolioEntries`, the `portfolio-entry` rows granted by his active, unexpired shares, projecting `portfolioId`, `title`, `evidenceKind`, `attachmentRef` and `reflectionText`. An entry outside a share's selection MUST NOT show. A revoked or expired share MUST grant no entry. The join MUST declare `via.when: { field: lifecycle, in: [active] }` and `via.validUntilField: expiresAt`. The collection MUST NOT ship before portaliq honours both (REQ-SMO-023). This is new work: the shared content is a flagged follow-up today.

#### Scenario: Only the selected entries
- GIVEN a share of two of Milan's five portfolio entries to Henk
- WHEN Henk opens the share
- THEN he sees those two and not the other three
- AND the page reads "Milan deelde 2 van zijn 5 onderdelen met u. De andere 3 ziet u niet."
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: An expired share opens nothing
- GIVEN a share to Henk whose `expiresAt` has passed
- WHEN Henk opens a link to one of its entries
- THEN no entry opens, and the page reads "Deze deling is verlopen. Er is niets meer te zien."
- @e2e exclude enforced by portaliq's `via.validUntilField` (REQ-SMO-023); covered by a portaliq reader test and a PortalContributionProviderTest on the declared join

### Requirement: Every assessor label on the site reads in Dutch

The `external-assessor` manifest MUST pass through `PortalLabelTranslator`. Every label MUST have a Dutch entry in the "u" form.

#### Scenario: The assessor site reads Dutch
- GIVEN the Esdoorn Techniek College site in Dutch
- WHEN Henk opens "Gedeeld met u"
- THEN every heading and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

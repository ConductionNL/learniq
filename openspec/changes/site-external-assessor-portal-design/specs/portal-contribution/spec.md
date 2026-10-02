## ADDED Requirements

### Requirement: An external assessor lands on what is shared with him and until when

The `external-assessor` audience MUST declare pages. The first MUST be `eaOverview`, "Overzicht", showing an access notice, then one row per active share with the candidate's name, the portfolio title and the share's end date. The notice MUST give the latest `expiresAt` of his active shares, or say that the school ends the access when an active share has none. The audience MUST stay read-only: no action is added. Design of record: `LearniqAssessor.dc.html`, the access notice and the candidate column.

#### Scenario: Ruud sees his access window
- GIVEN assessor Ruud Jansen with two active shares ending 16 October and 9 October
- WHEN he signs in on the examenportaal
- THEN the notice reads that he has access up to and including 16 October
- AND he sees one row per share with the candidate's name
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A revoked share disappears
- GIVEN the school revokes one of Ruud's shares
- WHEN he opens "Overzicht"
- THEN that candidate is no longer listed
- @e2e exclude server filter; pinned by PortalContributionProviderTest on `filter: lifecycle active`

### Requirement: NEW: A share names its candidate and portfolio

`portfolio-share` MUST carry `portfolioTitle` and `learnerName`, readable copies stamped by the server when the share is created and never written by a client. `eaSharedPortfolios` MUST project both. This is new work: a share holds only pointers today.

#### Scenario: The candidate's name on the share
- GIVEN a share of Daan Visser's portfolio "Proeve meterkast" to Ruud
- WHEN Ruud opens "Overzicht"
- THEN the row reads "Daan Visser" and "Proeve meterkast"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A client cannot rename the candidate
- GIVEN a share create that sends `learnerName` "Iemand anders"
- WHEN the server saves it
- THEN `learnerName` holds the name of the portfolio's own learner
- @e2e exclude server stamp; covered by a unit test of the stamp written with the build

### Requirement: NEW: An external assessor opens the shared entries, and only those

The `external-assessor` audience MUST declare `eaSharedPortfolioEntries`, the `portfolio-entry` rows granted by his active, unexpired shares, projecting `portfolioId`, `title`, `evidenceKind`, `attachmentRef` and `reflectionText`. An entry outside a share's selection MUST NOT show. A revoked or expired share MUST grant no entry. The collection MUST NOT ship before portaliq can filter the joined share on `lifecycle` and `expiresAt`. This is new work: the shared content is a flagged follow-up today.

#### Scenario: Only the selected entries
- GIVEN a share of three of Daan's five portfolio entries to Ruud
- WHEN Ruud opens the share
- THEN he sees those three and not the other two
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: An expired share opens nothing
- GIVEN a share to Ruud whose `expiresAt` has passed
- WHEN Ruud opens a link to one of its entries
- THEN nothing opens
- @e2e exclude needs portaliq's joined-schema filter on `via`; covered by a portaliq reader test

### Requirement: Every assessor label on the site reads in Dutch

The `external-assessor` manifest MUST pass through `PortalLabelTranslator`. Every label MUST have a Dutch entry in the "u" form.

#### Scenario: The assessor site reads Dutch
- GIVEN the Esdoorn Techniek College site in Dutch
- WHEN Ruud opens "Overzicht"
- THEN every heading and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

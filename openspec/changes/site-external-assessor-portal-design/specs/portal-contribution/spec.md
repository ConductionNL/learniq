## ADDED Requirements

### Requirement: An external assessor lands on what is shared with him and until when

The `external-assessor` audience MUST declare pages under `group: Mijn omgeving`, with `menu: false` on the default collection pages. The first MUST be `eaOverview`, "Overzicht", with `home: true`, showing a block "Uw toegang" with the end date of his latest-ending active share (a `collection` block over `eaSharedPortfolios`, `sort: { field: expiresAt, direction: desc }`, `limit: 1`), then one row per active share with the candidate's name, the portfolio title and the share's end date. Each share's record page MUST carry the sentence "U heeft toegang tot en met {expiresAt}." as a `richText` `template`, with `whenEmpty` "U heeft toegang zonder einddatum. De school beëindigt de toegang." for a share without an end date. The audience MUST stay read-only: no action is added. Design of record: `LearniqAssessor.dc.html`, the access notice and the candidate column.

#### Scenario: Ruud sees his access window
- GIVEN assessor Ruud Jansen with two active shares ending 16 October and 9 October
- WHEN he signs in on the examenportaal
- THEN "Uw toegang" shows 16 October
- AND he sees one row per share with the candidate's name
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A share without an end date
- GIVEN a share to Ruud with no `expiresAt`
- WHEN he opens that share
- THEN he reads "U heeft toegang zonder einddatum. De school beëindigt de toegang."
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

The `external-assessor` audience MUST declare `eaSharedPortfolioEntries`, the `portfolio-entry` rows granted by his active, unexpired shares, projecting `portfolioId`, `title`, `evidenceKind`, `attachmentRef` and `reflectionText`. An entry outside a share's selection MUST NOT show. A revoked or expired share MUST grant no entry. The join MUST declare `via.when: { field: lifecycle, in: [active] }` and `via.validUntilField: expiresAt`. The collection MUST NOT ship before portaliq honours both (REQ-SMO-023). This is new work: the shared content is a flagged follow-up today.

#### Scenario: Only the selected entries
- GIVEN a share of three of Daan's five portfolio entries to Ruud
- WHEN Ruud opens the share
- THEN he sees those three and not the other two
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: An expired share opens nothing
- GIVEN a share to Ruud whose `expiresAt` has passed
- WHEN Ruud opens a link to one of its entries
- THEN nothing opens
- @e2e exclude enforced by portaliq's `via.validUntilField` (REQ-SMO-023); covered by a portaliq reader test and a PortalContributionProviderTest on the declared join

### Requirement: Every assessor label on the site reads in Dutch

The `external-assessor` manifest MUST pass through `PortalLabelTranslator`. Every label MUST have a Dutch entry in the "u" form.

#### Scenario: The assessor site reads Dutch
- GIVEN the Esdoorn Techniek College site in Dutch
- WHEN Ruud opens "Overzicht"
- THEN every heading and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)
